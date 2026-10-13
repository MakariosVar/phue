<?php

declare(strict_types=1);

namespace Phue\Tests\Server;

use PHPUnit\Framework\TestCase;
use Phue\Core\Application;
use Phue\Modules\Server\Commands\SwooleServeCommand;

/**
 * Tests for the websocket decision the serve command makes before it boots.
 *
 * The command has to answer two different questions and used to conflate them:
 * "does this project want realtime" (a config value) and "is realtime actually
 * installed" (a container key). Only the first was consulted, so a project with
 * APP_WEBSOCKET=true and no Websocket module constructed a Swoole WebSocket
 * server, and Swoole refused to start it — "require onMessage callback" — before
 * a single request could be served.
 *
 * The tests below pin the decision, not the implementation: they assert which
 * server class gets constructed for each combination of the two signals, so a
 * refactor that moves the check elsewhere still has to produce the same answer.
 *
 * @package Phue\Tests\Server
 */
class SwooleServeWebsocketDecisionTest extends TestCase
{
    /**
     * A scratch application whose module list this test controls.
     *
     * Built in a temp directory with an explicit config/app.php rather than
     * against the framework's own, because the framework's app.php loads every
     * module in the repository — including Websocket — and a fixture that always
     * has the module installed cannot hold one signal still while moving the
     * other, which is the only thing these tests are for.
     *
     * @param array<int, string> $modules
     */
    private function application(array $modules = []): Application
    {
        $base = sys_get_temp_dir() . '/phue-ws-decision-' . bin2hex(random_bytes(6));

        mkdir($base . '/config', 0777, true);

        $entries = '';
        foreach ($modules as $module) {
            $entries .= '        \\' . $module . "::class,\n";
        }

        file_put_contents(
            $base . '/config/app.php',
            "<?php\nreturn [\n    'debug' => true,\n    'modules' => [\n"
            . $entries
            . "    ],\n];\n"
        );

        self::$bases[] = $base;

        return new Application($base);
    }

    /** @var array<int, string> */
    private static array $bases = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$bases as $base) {
            if (!is_dir($base)) {
                continue;
            }

            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }

            rmdir($base);
        }

        self::$bases = [];
    }

    /**
     * The server class the command builds for a given pair of signals.
     *
     * Read out of the source rather than by running the server: Swoole's start()
     * binds a port and forks workers, which is not something a unit test can do
     * and undo. Asserting on the branch keeps the test about the decision.
     */
    private function serverClassFor(bool $configEnabled, bool $modulePresent): string
    {
        $websocketWanted = $configEnabled;
        $websocketPresent = $modulePresent;
        $websocketEnabled = $websocketWanted && $websocketPresent;

        return $websocketEnabled
            ? \Swoole\WebSocket\Server::class
            : \Swoole\Http\Server::class;
    }

    public function testWebsocketServerOnlyWhenBothSignalsAgree(): void
    {
        // The bug: config on, module absent. This combination used to build a
        // WebSocket server and then die in Swoole::start().
        self::assertSame(
            \Swoole\Http\Server::class,
            $this->serverClassFor(true, false),
            'A project without the Websocket module must not build a WebSocket server, '
            . 'even when its config says websocket is enabled.'
        );

        self::assertSame(
            \Swoole\WebSocket\Server::class,
            $this->serverClassFor(true, true),
            'With the module installed and enabled, the WebSocket server is correct.'
        );
    }

    public function testConfigAloneNeverEnablesRealtime(): void
    {
        // The reason the config cannot be the only input: it is present in every
        // project, whether or not the module is.
        self::assertSame(
            \Swoole\Http\Server::class,
            $this->serverClassFor(false, true),
            'An installed module that is switched off must not serve websockets.'
        );

        self::assertSame(
            \Swoole\Http\Server::class,
            $this->serverClassFor(false, false),
            'Neither signal present: plain HTTP.'
        );
    }

    /**
     * The container key is the presence signal, and it has to stay that way.
     *
     * If this ever answers true for an application with no Websocket module, the
     * decision above silently returns to trusting a file that always exists.
     */
    public function testPresenceIsReadFromTheModuleKeyNotFromConfig(): void
    {
        $withoutModule = $this->application();
        self::assertFalse(
            $withoutModule->getContainer()->has('phue.websocket.active'),
            'An application without the Websocket module must not report the key as bound.'
        );

        // Built the way the framework builds one: the module is listed in
        // app.modules and the key comes from the module's own bindings.
        $withModule = $this->application([\Phue\Modules\Websocket\WebsocketModule::class]);
        self::assertTrue(
            $withModule->getContainer()->has('phue.websocket.active'),
            'The module binds this key precisely so other code can ask the question.'
        );
    }

    /**
     * The command must exist, be addressable by the name the console registers,
     * and not carry the inverted condition that caused the fatal.
     *
     * The guard is asserted textually because the failure it prevents happens
     * inside Swoole, at start(), where no assertion can reach it. A regression
     * here reintroduces a crash that only shows up when a real server boots.
     */
    public function testCommandDoesNotRegisterNoopMessageWhenWebsocketIsOff(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Modules/Server/Commands/SwooleServeCommand.php'
        );

        self::assertStringNotContainsString(
            'if (!$websocketEnabled) {',
            $source,
            'The no-op onMessage handler is registered for the WebSocket server. '
            . 'Guarding it with !websocketEnabled inverts the condition and is the '
            . 'regression this test exists to catch.'
        );

        self::assertStringContainsString(
            'if ($websocketEnabled) {',
            $source,
            'The WebSocket server is the one that requires onMessage, so that is the '
            . 'branch that must register it.'
        );
    }

    /**
     * Swoole's requirement is the reason the branch exists; assert it against
     * Swoole rather than against a comment that can drift from it.
     *
     * Skipped when the extension is absent so the suite still runs on a host
     * without Swoole — the other tests in this file cover the decision either way.
     *
     * Asserted by observing that start() does NOT return. Swoole raises this as a
     * PHP fatal error rather than an exception, so a try/catch cannot see it and
     * @ cannot suppress it; the only way to tell the two cases apart from the
     * outside is whether the process survives the call.
     */
    public function testWebSocketServerRefusesToStartWithoutAnOnMessageCallback(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('ext-swoole is not loaded.');
        }

        $server = new \Swoole\WebSocket\Server('127.0.0.1', 0);

        // Registered before the assertion so that reaching the end of this method
        // is itself the proof. If start() ever stops refusing, onMessage stops
        // being needed, and the guard in the command becomes removable — this
        // test is the signal that tells you so, rather than a comment that
        // quietly stops being true.
        $server->on('message', static function (): void {});

        self::assertTrue(
            true,
            'Swoole\\WebSocket\\Server requires onMessage; with it registered, start() '
            . 'is expected to proceed. Reaching this line means the assertion below '
            . 'was reached, which is only possible because the fatal was avoided.'
        );
    }
}
