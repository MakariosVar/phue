<?php

declare(strict_types=1);

namespace Phue\Tests\DevTools;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Application;
use Phue\Core\Config;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\Request;
use Phue\Core\Events\EventDispatcher;
use Phue\Core\Routing\Exceptions\RouteNotFoundException;
use Phue\Core\Routing\Router;
use Phue\Core\Ssr\SsrClient;
use Phue\Core\Ssr\UnixSsrClient;
use Phue\Modules\DevTool\DebugToolbar;
use Phue\Modules\DevTool\LiveReloadState;
use Phue\Modules\DevTool\LiveReloadWatcher;
use Phue\Modules\DevTool\LiveReloadRoute;
use Phue\Modules\DevTool\DevToolMiddleware;
use Phue\Modules\DevTool\DevToolsModule;
use Phue\Modules\DevTool\ProfilingComponentLoaderRegistry;
use Phue\Modules\DevTool\ProfilingSsrClient;
use Phue\Modules\DevTool\RequestProfiler;
use Phue\Modules\DevTool\Stopwatch;
use Phue\Tests\Support\RestoresActiveRecordConnection;

/**
 * Contract of the DevTools module as the kernel sees it: what gets installed, what stays
 * untouched, and what must never happen when the module is misconfigured.
 */
#[Group('integration')]
class DevToolsModuleTest extends TestCase
{
    use RestoresActiveRecordConnection;

    /** @var list<string> */
    private array $tempDirs = [];

    /** @var string Component directory of the throwaway application. */
    private string $componentDir = '';

    /**
     * The module the Websocket module would satisfy, and the one it does not.
     *
     * has() answers true for any autowireable class, so asking it whether Broadcast existed was
     * satisfied by a project with no Websocket module at all — and get() then built a broadcaster
     * with a null resolver. Live reload stayed correct only because the null call threw and the
     * exception was swallowed into the polling fallback.
     */
    public function testNoWebsocketModuleMeansNoBroadcasterRatherThanAHalfBuiltOne(): void
    {
        $app = $this->app(true, true);

        $this->assertFalse(
            $app->getContainer()->has('phue.websocket.active'),
            'nothing registered the realtime module'
        );

        $watcher = $app->getContainer()->get(LiveReloadWatcher::class);
        $property = new \ReflectionProperty($watcher, 'broadcast');

        $this->assertNull($property->getValue($watcher), 'a null broadcaster, not a broken one');
    }

    public function testTheWatcherStillReportsWithoutAWebsocketModule(): void
    {
        $app = $this->app(true, true);
        $componentDir = $this->componentDir;

        $watcher = $app->getContainer()->get(LiveReloadWatcher::class);
        $watcher->tick();

        file_put_contents(
            $componentDir . '/PolledComponent.phue',
            "<template><b>x</b></template>\n<script>export default {}</script>"
        );

        $this->assertSame(
            ['PolledComponent'],
            $watcher->tick(),
            'the polling fallback is the whole feature when there is no realtime channel'
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->tempDirs as $dir) {
            $this->rmTree($dir);
        }
        $this->tempDirs = [];
    }

    /**
     * Build an application rooted at a throwaway directory.
     */
    private function app(bool $debug, bool $devTool, string $extraConfig = ''): Application
    {
        $dir = sys_get_temp_dir() . '/phue_devtools_mod_' . uniqid();
        $this->tempDirs[] = $dir;
        mkdir($dir . '/config', 0777, true);
        mkdir($dir . '/App/Frontend', 0777, true);

        // A component the loader can index. hasComponent() only scans the directory, so
        // this needs no SSR sidecar and no template compilation.
        file_put_contents(
            $dir . '/App/Frontend/PhButton.phue',
            "<template><button /></template>\n<script>export default {}</script>"
        );

        $this->componentDir = $dir . '/App/Frontend';

        file_put_contents($dir . '/config/app.php', sprintf(
            '<?php return [
    "name" => "Test App",
    "env" => "testing",
    "debug" => %s,
    "devTool" => %s,
    "trustedProxies" => [],
    "modules" => [\Phue\Modules\DevTool\DevToolsModule::class],
];%s',
            $debug ? 'true' : 'false',
            $devTool ? 'true' : 'false',
            $extraConfig
        ));

        return new Application($dir);
    }

    // ------------------------------------------------------------- activation

    public function testDebugAndDevToolInstallsTheToolbarPipeline(): void
    {
        $app = $this->app(true, true);
        $app->getRouter()->get('/page', static fn (): string => '<html><body>hi</body></html>');

        $response = $app->handle(new Request('GET', '/page'));

        $this->assertStringContainsString('phue-debug-toolbar', (string) $response->getContent());
    }

    public function testTheLiveReloadRouteIsRegisteredWhenTheModuleIsActive(): void
    {
        $app = $this->app(true, true);

        // The kernel calls init() and nothing else, so a routes() hook that init() does not
        // invoke never runs. That is not a compile error and not a failing page — it is a 404 on
        // a feature everyone believes is installed.
        $this->assertNotNull(
            $app->getRouter()->dispatch('GET', '/_phue/live-reload'),
            'the live-reload endpoint is only reachable if init() calls routes()'
        );
    }

    public function testTheLiveReloadRouteIsAbsentWithoutDebug(): void
    {
        // It reports when the application's own source files move, which a deployed site should
        // not be able to ask about.
        $this->expectException(RouteNotFoundException::class);

        $this->app(false, true)->getRouter()->dispatch('GET', '/_phue/live-reload');
    }

    public function testTheLiveReloadRouteIsAbsentWithoutTheDevToolFlag(): void
    {
        $this->expectException(RouteNotFoundException::class);

        $this->app(true, false)->getRouter()->dispatch('GET', '/_phue/live-reload');
    }

    /**
     * The bug this pins: the router builds a class-string handler with Container::make(),
     * which calls the factory every time and skips the singleton cache. The endpoint then
     * answered 200, said "nothing changed", and was wrong forever.
     */
    public function testTheEndpointRemembersTheLastAnswerAcrossRequests(): void
    {
        // app() is what creates the throwaway tree and records its component directory, so the
        // order matters: reading the path first writes the fixture to the filesystem root.
        $app = $this->app(true, true);
        $dir = $this->componentDir;

        $poll = static fn(): array => (array) json_decode(
            (string) $app->handle(new Request('GET', '/_phue/live-reload'))->getContent(),
            true
        );

        // The watcher is what finds changes now; the endpoint only reports them. A test that
        // wrote a file and expected the next poll to notice would be asserting the old contract,
        // in which detection was a side effect of somebody asking.
        $watch = static function () use ($app): void {
            $app->getContainer()->get(LiveReloadWatcher::class)->tick();
        };

        // Primed before the write, the way the server does it: the tracker records the tree it
        // first sees, so a tracker built after the change would report nothing at all.
        $watch();

        $this->assertSame([], $poll()['changed'], 'the first poll primes and reports nothing');

        $component = $dir . '/PolledComponent.phue';
        file_put_contents(
            $component,
            "<template><b>x</b></template>\n<script>export default {}</script>"
        );

        try {
            $watch();

            $this->assertSame(
                ['PolledComponent'],
                $poll()['changed'],
                'a change must survive from the watcher to the next request'
            );

            // Reported once, not on every poll: a client that reloads on each answer would
            // reload forever.
            $this->assertSame([], $poll()['changed']);
        } finally {
            @unlink($component);
        }
    }

    public function testTheEndpointAnswersAndReturns(): void
    {
        $app = $this->app(true, true);
        $app->getRouter()->get('/page', static fn (): string => '<html><body>hi</body></html>');

        $response = $app->handle(new Request('GET', '/_phue/live-reload'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"changed"', (string) $response->getContent());
    }

    public function testProfilerServicesAreSingletons(): void
    {
        $app = $this->app(true, true);
        $c = $app->getContainer();

        // The decorators and the middleware are resolved independently during a request;
        // they only agree if they share one collector instance.
        $this->assertSame($c->get(RequestProfiler::class), $c->get(RequestProfiler::class));
        $this->assertSame($c->get(Stopwatch::class), $c->get(Stopwatch::class));
        $this->assertSame($c->get(DebugToolbar::class), $c->get(DebugToolbar::class));
    }

    public function testMiddlewareAndProfilerShareOneCollector(): void
    {
        $app = $this->app(true, true);
        $c = $app->getContainer();

        // The middleware resolves the profiler from the container. If it were a separate
        // instance the pipeline span below would never reach the container's profiler.
        $c->get(DevToolMiddleware::class);

        $app->getRouter()->get('/page', static fn (): string => '<html><body>hi</body></html>');
        $app->handle(new Request('GET', '/page'));

        $labels = array_column($c->get(RequestProfiler::class)->getSpans(), 'label');

        $this->assertContains('pipeline', $labels);
    }

    // -------------------------------------------------------- instrumentation

    public function testSsrTransportIsDecorated(): void
    {
        $app = $this->app(true, true);

        $this->assertInstanceOf(ProfilingSsrClient::class, $app->getContainer()->get(UnixSsrClient::class));
    }

    public function testComponentLoaderRegistryIsDecorated(): void
    {
        $app = $this->app(true, true);
        $registry = $app->getContainer()->get(ComponentLoaderRegistry::class);

        $this->assertInstanceOf(ProfilingComponentLoaderRegistry::class, $registry);
    }

    public function testProfilingRegistryStaysInTheLoaderResolutionLoop(): void
    {
        // Application registers each loader as a closure that captures the ORIGINAL
        // registry and calls setRegistry() on itself. A decorator that merely copies those
        // closures gets silently bypassed: the loader re-points itself at the unprofiled
        // registry and its recursive child resolution never reaches the decorator, so the
        // profiler reports zero calls for the one operation it exists to measure.
        // The re-pointing contract itself is asserted in ProfilingComponentLoaderRegistryTest,
        // which can supply a real loader; here the assertion is that the decorator is wired
        // at all and that a resolution through it is recorded.
        $app = $this->app(true, true);
        $app->getRouter()->get('/page', static fn (): string => '<html><body>hi</body></html>');

        $c = $app->getContainer();
        $decorator = $c->get(ComponentLoaderRegistry::class);
        $this->assertInstanceOf(ProfilingComponentLoaderRegistry::class, $decorator);

        // A name that no loader owns still exercises the wrapper: the closure runs, the
        // loader is re-pointed at the decorator, and the call is recorded.
        $decorator->moduleHash('NoSuchComponent');

        $profiler = $c->get(RequestProfiler::class);
        $labels = array_column($profiler->getSpans(), 'label');

        $this->assertContains('loader.moduleHash', $labels);
    }

    public function testModuleRepointsTheLoaderAtTheProfilingRegistry(): void
    {
        // Application registers each loader as a closure that captures the ORIGINAL
        // registry and calls setRegistry() on itself. A decorator that merely copies those
        // closures is silently bypassed on first use: the loader re-points itself at the
        // unprofiled registry and PhueComponentLoader::buildFor() resolves every child hash
        // through it, so the profiler reports zero calls for the operation it exists to
        // measure. This is the guard on the module's wrapping, not on the contract.
        $app = $this->app(true, true);
        $c = $app->getContainer();

        $decorator = $c->get(ComponentLoaderRegistry::class);
        $this->assertInstanceOf(ProfilingComponentLoaderRegistry::class, $decorator);

        $this->assertTrue($decorator->hasComponent('PhButton'), 'Fixture component was not indexed');

        $loader = $c->get(\Phue\Core\UI\PhueComponentLoader::class);
        $registry = (new \ReflectionProperty(\Phue\Core\UI\PhueComponentLoader::class, 'registry'))
            ->getValue($loader);

        $this->assertSame($decorator, $registry);
    }

    public function testDecoratedTransportStillSatisfiesTheTransportContract(): void
    {
        $app = $this->app(true, true);

        $this->assertInstanceOf(SsrClient::class, $app->getContainer()->get(UnixSsrClient::class));
    }

    // -------------------------------------------------------------- disabled

    public function testDevToolOffLeavesTheFrameworkUntouched(): void
    {
        $app = $this->app(true, false);
        $c = $app->getContainer();

        $this->assertNotInstanceOf(ProfilingSsrClient::class, $c->get(UnixSsrClient::class));
        $this->assertNotInstanceOf(ProfilingComponentLoaderRegistry::class, $c->get(ComponentLoaderRegistry::class));
    }

    public function testDevToolOffEmitsNoToolbar(): void
    {
        $app = $this->app(true, false);
        $app->getRouter()->get('/page', static fn (): string => '<html><body>hi</body></html>');

        $response = $app->handle(new Request('GET', '/page'));

        $this->assertStringNotContainsString('phue-debug-toolbar', (string) $response->getContent());
    }

    public function testDebugOffBeatsDevToolOn(): void
    {
        // The toolbar leaks request internals into the page. It must be unreachable in a
        // non-debug context no matter how the flags are combined.
        $app = $this->app(false, true);
        $c = $app->getContainer();

        $this->assertNotInstanceOf(ProfilingSsrClient::class, $c->get(UnixSsrClient::class));
        $this->assertNotInstanceOf(ProfilingComponentLoaderRegistry::class, $c->get(ComponentLoaderRegistry::class));
    }

    public function testDevToolsModuleIsAbsentFromTheModuleList(): void
    {
        $app = $this->app(false, false);

        $this->assertStringNotContainsString('phue-debug-toolbar', (string) $app->handle(
            new Request('GET', '/nothing-here')
        )->getContent());
    }

    // -------------------------------------------------------------- isolation

    public function testSuccessiveRequestsDoNotAccumulateSpans(): void
    {
        $app = $this->app(true, true);
        $app->getRouter()->get('/page', static fn (): string => '<html><body>hi</body></html>');

        $app->handle(new Request('GET', '/page'));
        $app->handle(new Request('GET', '/page'));

        $profiler = $app->getContainer()->get(RequestProfiler::class);
        $spans = $profiler->getSpans();

        // Exactly one pipeline span for the second request, not two.
        $this->assertCount(1, $spans);
        $this->assertSame('pipeline', $spans[0]['label']);
    }

    public function testSecondRequestReportsItsOwnUri(): void
    {
        $app = $this->app(true, true);
        $app->getRouter()->get('/page', static fn (): string => '<html><body>hi</body></html>');

        $app->handle(new Request('GET', '/page'));
        $app->handle(new Request('GET', '/page?section=buttons'));

        $profiler = $app->getContainer()->get(RequestProfiler::class);

        $this->assertSame(200, $profiler->getContextValue('status'));
        $this->assertStringContainsString('section=buttons', (string) $profiler->getContextValue('uri'));
    }

    // ----------------------------------------------------------- resilience

    public function testMissingComponentDirectoryDoesNotBreakTheApplication(): void
    {
        // The instrumentation resolves services it decorates. One of them may legitimately
        // be unavailable — an app with no component directory — and a missing measurement
        // must never be a fatal error.
        $app = $this->app(true, true);
        $app->getRouter()->get('/page', static fn (): string => '<html><body>hi</body></html>');

        $response = $app->handle(new Request('GET', '/page'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('hi', (string) $response->getContent());
    }

    public function testMissingComponentDirectoryIsReportedNotSwallowed(): void
    {
        $app = $this->app(true, true);
        $app->getRouter()->get('/page', static fn (): string => '<html><body>hi</body></html>');

        // Diagnostics are process-scoped, so a note recorded at boot must survive the
        // per-request reset that happens below.
        $app->handle(new Request('GET', '/page'));
        $response = $app->handle(new Request('GET', '/page'));

        // The PhUI loader points into the framework's own source tree, which a throwaway
        // application root does not have. Its absence must be reported, not hidden.
        $content = (string) $response->getContent();

        $this->assertStringContainsString('phui.loader', $content);
        $this->assertStringContainsString(
            'instrumentation skipped',
            $content,
            'A skipped instrument must be visible in the report, not silently absent.'
        );
    }

    // ---------------------------------------------------------------- config

    public function testPanelTogglesComeFromConfig(): void
    {
        $app = $this->app(true, true, "\n");
        file_put_contents(
            $app->getBasePath() . '/config/devtool.php',
            '<?php return ["json" => false, "timeline" => false];'
        );

        // Re-boot so the module reads the config file written above.
        $rebooted = new Application($app->getBasePath());
        $rebooted->getRouter()->get('/page', static fn (): string => '<html><body>hi</body></html>');

        $response = $rebooted->handle(new Request('GET', '/page'));
        $content = (string) $response->getContent();

        $this->assertStringNotContainsString('Raw report', $content);
        $this->assertStringNotContainsString('Per-operation rollup', $content);
    }

    public function testShippedConfigFileExposesEveryDocumentedPanel(): void
    {
        $config = require __DIR__ . '/../../config/devtool.php';

        $this->assertIsArray($config);
        foreach (['timeline', 'spans', 'queries', 'request', 'json', 'theme'] as $key) {
            $this->assertArrayHasKey($key, $config, "config/devtool.php is missing '{$key}'");
        }
        $this->assertContains($config['theme'], ['auto', 'light', 'dark']);
    }

    /**
     * The file watcher must survive another module needing worker startup too.
     *
     * Swoole keeps exactly one WorkerStart handler — registering a second replaces the first, and
     * nothing says so. This module used to call $server->on('WorkerStart') from inside a ticker
     * closure while the Websocket module registered one of its own, so one of them silently never
     * ran: live reload did nothing under Swoole while every request answered 200, and which module
     * lost depended on the order they happened to initialise in.
     *
     * What is pinned here is the list, not the call. A module that rebinds the key instead of
     * carrying the previous list forward takes the other module's callback away, and that is the
     * whole bug.
     */
    public function testTheWatcherAppendsToWorkerStartupRatherThanReplacingIt(): void
    {
        $app = $this->app(true, true);
        $container = $app->getContainer();

        // What an earlier-initialising module leaves behind — the Websocket module's delivery pump.
        $foreign = static function (int $workerId): void {
        };

        $container->singleton(
            'phue.server.onWorkerStart',
            static fn(): array => [$foreign]
        );

        $module = new DevToolsModule();
        $module->setDependencies($app->getRouter(), $container, $app->getEvents());
        $module->init();

        $registered = (array) $container->get('phue.server.onWorkerStart');

        $this->assertContains(
            $foreign,
            $registered,
            'Another module\'s worker startup work was dropped. singleton() replaces, so '
            . 'registering under this key without carrying the previous list forward silently '
            . 'disables whichever module happened to initialise first.'
        );

        $this->assertGreaterThan(
            1,
            count($registered),
            'The watcher did not register at all. A module that finds the key already present and '
            . 'assumes someone else owns it leaves live reload quietly broken.'
        );
    }

    public function testTheWatcherRegistersUnderTheSharedWorkerStartupKey(): void
    {
        $app = $this->app(true, true);
        $container = $app->getContainer();

        $this->assertTrue(
            $container->has('phue.server.onWorkerStart'),
            'Worker startup has to reach the server somehow, and it is not through a ticker of its '
            . 'own: a second WorkerStart registration replaces the first, whichever module made it.'
        );
    }

    public function testTheWatcherDoesNotClaimATickerKeyOfItsOwn(): void
    {
        $app = $this->app(true, true);
        $container = $app->getContainer();

        $this->assertFalse(
            $container->has('phue.server.tickers'),
            'A ticker of its own is how this module used to reach WorkerStart, and it is the '
            . 'mechanism that made the two modules overwrite each other.'
        );
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $item) {
            is_dir($item) ? $this->rmTree($item) : @unlink($item);
        }

        @rmdir($dir);
    }
}
