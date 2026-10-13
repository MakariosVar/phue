<?php

declare(strict_types=1);

namespace Phue\Tests\Console;

use PHPUnit\Framework\TestCase;
use Phue\Core\Console\Commands\NewAppCommand;

/**
 * Tests for the scaffolder that replaced the shell installer.
 *
 * Three things are worth pinning, and they are not the same kind of thing.
 *
 * The frame arithmetic is the reason this file exists at all. The installer
 * drew its menu by counting the lines it had printed and moving the cursor back
 * up that many, in bash — arithmetic that could only be observed by driving a
 * pty from a second language, and which was wrong in three separate ways before
 * it was moved into a class: the count accumulated across frames instead of
 * measuring the last one, the frame was allowed to be taller than the terminal,
 * and long lines were allowed to wrap while still counting as one line. Each
 * of those produces a menu that crawls off the screen or degrades into
 * fragments as the cursor moves, and none of them is reachable from a
 * behavioural test. Here they are three methods over integers.
 *
 * The mandatory module is an invariant with teeth: the Swoole adapter is what
 * `serve` runs, and a project without it cannot serve. Every selection path has
 * to end up containing it, including the ones a user might think disable it.
 *
 * And config/app.php has to stay loadable. A scaffolder that writes a file the
 * application cannot parse produces a project that is broken at the exact
 * moment it was created, which is the worst time to find out.
 *
 * @package Phue\Tests\Console
 */
final class NewAppCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = dirname(__DIR__, 2);
    }

    // --------------------------------------------------------- frame fitting

    public function testFitShortensOnlyTextThatWouldWrap(): void
    {
        $command = new NewAppCommand();

        self::assertSame('short', $command->fit('short', 40), 'Text that fits is returned untouched.');

        $cut = $command->fit(str_repeat('a', 40), 10);

        self::assertSame(10, mb_strlen($cut), 'A cut line must occupy exactly the width it was given.');
        self::assertStringEndsWith('…', $cut, 'A cut line says so, rather than looking truncated by accident.');
    }

    /**
     * The defect, stated as an invariant.
     *
     * A line longer than the terminal wraps onto a second row while the frame
     * counter still counts it as one. The redraw then erases a row short of the
     * frame it drew, and the row above keeps a fragment of the previous frame —
     * which is what "the whole TUI is reprinted from the bottom" looked like
     * from the outside.
     */
    public function testNoDrawnLineCanExceedTheTerminalWidth(): void
    {
        $command = new NewAppCommand();

        foreach ([20, 40, 80, 100, 200] as $width) {
            $line = $command->fit(str_repeat('x', 500), $width);

            self::assertLessThanOrEqual(
                $width,
                mb_strlen($line),
                "A {$width}-column terminal cannot show a line of " . mb_strlen($line) . ' columns.'
            );
        }
    }

    public function testFitNeverReturnsAnUnusableWidth(): void
    {
        $command = new NewAppCommand();

        // A width of zero or one cannot even hold the ellipsis. Returning
        // something that long would overflow again, which is the whole problem.
        foreach ([0, 1, 2, -5] as $width) {
            self::assertLessThanOrEqual(
                max($width, 8),
                mb_strlen($command->fit('some text', $width))
            );
        }
    }

    // ---------------------------------------------------------- scroll window

    public function testScrollWindowFollowsTheCursorDown(): void
    {
        $command = new NewAppCommand();

        $command->scrollWindow(total: 20, visible: 5, cursor: 0);
        self::assertSame(0, $command->viewFrom(), 'At the top, nothing is hidden above.');

        $command->scrollWindow(total: 20, visible: 5, cursor: 4);
        self::assertSame(0, $command->viewFrom(), 'A cursor inside the window does not move it.');

        $command->scrollWindow(total: 20, visible: 5, cursor: 5);
        self::assertSame(1, $command->viewFrom(), 'Stepping past the fold scrolls by one row, not by the whole list.');
    }

    public function testScrollWindowStopsAtTheEndOfTheList(): void
    {
        $command = new NewAppCommand();

        $command->scrollWindow(total: 20, visible: 5, cursor: 19);

        self::assertSame(15, $command->viewFrom(), 'The last page is the final five rows, not an empty window past the end.');
        self::assertLessThanOrEqual(15, $command->viewFrom());
    }

    /**
     * The half of the window rule that is easy to leave out.
     *
     * A list scrolled to the bottom with the cursor above the fold is a menu
     * the user cannot leave: pressing up moves the highlight and not the list,
     * so nothing appears to happen.
     */
    public function testScrollWindowComesBackUpWhenTheCursorReturns(): void
    {
        $command = new NewAppCommand();

        $command->scrollWindow(total: 20, visible: 5, cursor: 19);
        self::assertSame(15, $command->viewFrom());

        $command->scrollWindow(total: 20, visible: 5, cursor: 0);
        self::assertSame(0, $command->viewFrom(), 'Moving back to the first row brings the top of the list back.');
    }

    public function testScrollWindowHandlesListsShorterThanTheWindow(): void
    {
        $command = new NewAppCommand();

        $command->scrollWindow(total: 3, visible: 10, cursor: 2);

        self::assertSame(0, $command->viewFrom(), 'A list that fits never scrolls, however far the cursor moves.');
    }

    public function testBodyRowsLeavesRoomForTheFrameChrome(): void
    {
        $command = new NewAppCommand();

        $property = new \ReflectionProperty($command, 'rows');

        foreach ([24, 30, 40, 60] as $height) {
            $property->setValue($command, $height);
            $body = $command->bodyRows();

            self::assertGreaterThan(0, $body, "A {$height}-row terminal must leave room for a list.");
            self::assertLessThan(
                $height,
                $body,
                "Chrome plus body must fit a {$height}-row terminal; " . $height
                . " rows were budgeted and {$body} were left for the list."
            );
        }

    }

    // ------------------------------------------------------- mandatory module

    /**
     * Every path, including the ones that look like they clear the list.
     *
     * `n` is "none" and `a` is "all"; both are ways a user reaches for an empty
     * selection, and both must still leave the server adapter selected.
     */
    public function testEveryPathKeepsTheServerModule(): void
    {
        $command = new NewAppCommand();

        $method = new \ReflectionMethod($command, 'withMandatory');

        foreach ([[], ['rest'], ['auth', 'rest'], ['server']] as $input) {
            $result = $method->invoke($command, $input);

            self::assertContains(
                'server',
                $result,
                'The Swoole adapter is required by every Phue project; a selection of '
                . implode(',', $input) . ' must still include it.'
            );
        }

        self::assertCount(
            1,
            $method->invoke($command, ['server', 'server']),
            'The mandatory module is added, not duplicated.'
        );
    }

    /**
     * The command name.
     *
     * Asserted because `phue new` and `phue new:project` are different verbs
     * here — NewProjectCommand owns the other one — and the scaffolder only
     * earns its place if it is the obvious one to reach for.
     */
    public function testCommandIsNamedNewAndDescribed(): void
    {
        $command = new NewAppCommand();

        self::assertSame('new', $command->getName());
        self::assertNotSame('', $command->getDescription(), 'A command with no description is a command nobody finds.');
        self::assertNotSame(
            'project:new',
            $command->getName(),
            'NewProjectCommand already owns project scaffolding; this command must not collide with it.'
        );
    }

    // -------------------------------------------------------------- behaviour

    public function testUnknownModuleIsRejectedWithTheAvailableList(): void
    {
        $command = new NewAppCommand();

        $error = fopen('php://memory', 'r+');
        $method = new \ReflectionMethod($command, 'resolveList');

        $result = $method->invoke($command, 'rest,nonsense', ['rest' => 'REST', 'queue' => 'Queue'], $error);

        rewind($error);
        $message = (string) stream_get_contents($error);
        fclose($error);

        self::assertNull($result, 'An unresolvable name must not produce a selection.');
        self::assertStringContainsString('nonsense', $message, 'The error names what could not be resolved.');
        self::assertStringContainsString('rest, queue', $message, 'And says what would have been.');
    }

    /**
     * The lookup has to return an id, not a description.
     *
     * $available is id => summary. A case-insensitive array lookup on it hands
     * back the summary, and the summary then gets written into config/app.php
     * where a module class belongs — as a sentence, in the middle of a PHP
     * array, and only noticed when the application next boots.
     */
    public function testModuleLookupResolvesToIdsNotDescriptions(): void
    {
        $command = new NewAppCommand();

        $method = new \ReflectionMethod($command, 'resolveList');

        $error = fopen('php://memory', 'r+');
        $result = $method->invoke(
            $command,
            'REST, RateLimit',
            [
                'rest' => 'Bootstraps REST endpoints',
                'rateLimit' => 'Provides rate limiting',
            ],
            $error
        );
        fclose($error);

        self::assertSame(['rateLimit', 'rest', 'server'], $result, 'Ids, sorted, with the mandatory module.');
    }

    /**
     * The file the scaffolder writes has to be a file the application can read.
     *
     * Checked by loading it, not by matching its text: the failure mode is a
     * config the application cannot parse, and only loading it proves it parses.
     */
    public function testGeneratedAppConfigLoadsAndListsRealClasses(): void
    {
        $command = new NewAppCommand();

        $method = new \ReflectionMethod($command, 'renderAppConfig');

        $php = $method->invoke($command, [
            'Phue\\Modules\\Rest\\RestModule',
            'Phue\\Modules\\Server\\ServerModule',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'appcfg') . '.php';
        file_put_contents($path, $php);

        try {
            $config = require $path;

            self::assertIsArray($config);
            self::assertArrayHasKey('modules', $config);
            self::assertCount(2, $config['modules']);

            foreach ($config['modules'] as $class) {
                self::assertTrue(
                    class_exists($class),
                    "The scaffolder wrote $class into config/app.php, and no such class exists. "
                    . 'A config naming a class that is not there breaks the application at boot.'
                );
            }

            // declare(strict_types=1) must be the first statement in the file,
            // or PHP refuses it. A heredoc that starts with a blank line fails
            // here and nowhere else.
            self::assertStringStartsWith(
                '<?php',
                ltrim($php),
                'The generated file must open with the open tag.'
            );
            self::assertMatchesRegularExpression(
                '/^<\?php\s*\n\s*declare\(strict_types=1\);/',
                $php,
                'declare(strict_types=1) must be the first statement.'
            );
        } finally {
            @unlink($path);
        }
    }

    public function testEmptySelectionStillProducesAValidConfig(): void
    {
        $command = new NewAppCommand();

        $method = new \ReflectionMethod($command, 'renderAppConfig');

        $php = $method->invoke($command, []);

        $path = tempnam(sys_get_temp_dir(), 'appcfg') . '.php';
        file_put_contents($path, $php);

        try {
            $config = require $path;

            self::assertIsArray($config, 'An empty modules array is still a valid config.');
            self::assertSame([], $config['modules']);
        } finally {
            @unlink($path);
        }
    }

    // ----------------------------------------------------- module discovery

    /**
     * Modules are read from the package, not from a table in the command.
     *
     * The rule the original installer established and this one inherits: a
     * module added to the framework has to be installable without editing the
     * scaffolder, because a hardcoded list is a list that is wrong the day
     * someone adds a module.
     */
    public function testEveryModuleInThePackageIsDiscoverable(): void
    {
        $command = new NewAppCommand();

        $method = new \ReflectionMethod($command, 'availableModules');

        $discovered = $method->invoke($command, $this->basePath);

        self::assertNotEmpty($discovered, 'The framework ships modules; none were found.');

        foreach (glob($this->basePath . '/src/Modules/*') ?: [] as $dir) {
            $name = basename($dir);
            $classFile = $dir . '/' . $name . 'Module.php';

            if (!is_file($classFile)) {
                continue;
            }

            $code = (string) file_get_contents($classFile);
            preg_match('/public\s+string\s+\$id\s*=\s*\'([^\']*)\'/', $code, $m);

            if ($m === []) {
                continue;
            }

            self::assertArrayHasKey(
                $m[1],
                $discovered,
                "Module directory src/Modules/$name declares id '{$m[1]}', which the "
                . 'scaffolder did not discover. A module that cannot be discovered '
                . 'cannot be installed.'
            );
        }

        self::assertArrayHasKey(
            'server',
            $discovered,
            'The server module must be discoverable, since every project gets it.'
        );
    }

    public function testMandatoryModuleExistsInThisFramework(): void
    {
        // The constant is what makes the module mandatory; if the framework ever
        // drops it, the scaffolder would promise a module it cannot deliver.
        self::assertTrue(
            is_dir($this->basePath . '/src/Modules/Server'),
            'src/Modules/Server is expected to exist — the mandatory module is '
            . 'hardcoded in NewAppCommand as well as in the package.'
        );
    }
}
