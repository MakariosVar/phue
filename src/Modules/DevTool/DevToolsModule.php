<?php

declare(strict_types=1);

namespace Phue\Modules\DevTool;

use Phue\Core\Config;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\Hiit\ChangeTracker;
use Phue\Core\Module;
use Phue\Core\Ssr\SsrClient;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\TemplateCompilerBridge;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Class DevToolsModule
 *
 * Development-only profiling module for the Phue framework.
 *
 * Responsibilities:
 *  - Register the profiling services as explicit singletons so the decorators and the
 *    middleware share one collector.
 *  - Install the SSR transport, component-resolution and database-driver decorators that
 *    report into that collector.
 *  - Mount the DevTool middleware last, so its start() brackets the part of the request
 *    that application code is responsible for. SecurityMiddleware and
 *    ErrorHandlerMiddleware are pushed first and therefore run outside that bracket —
 *    the report is "where did the application spend its time", not the HTTP wall clock.
 *
 * Instrumentation is applied by decoration rather than by calls inside the framework core.
 * That keeps Phue\Core unaware of the DevTool module and means the same profiler works
 * for third-party services bound into the container.
 *
 * Every step is individually guarded. Resolving a service it decorates can legitimately
 * fail — an application with no component directory, or with no database — and a missing
 * measurement must never be the reason a page fails. A skipped instrument is recorded as a
 * diagnostic, surfaced in the report and logged, rather than swallowed.
 *
 * @package Phue\Modules\DevTool
 */
class DevToolsModule extends Module
{
    /**
     * Module identifier.
     */
    public string $id = 'devtools';

    /**
     * Required module dependencies.
     *
     * @var array<int, string>
     */
    public array $requires = [];

    /**
     * Initialize the module
     *
     * @return void
     */
    public function init(): void
    {
        $this->config();
        $this->bindings();
        $this->routes();
    }

    /**
     * Registers the live-reload endpoint.
     *
     * Gated on the same two flags as bindings(), for the same reason: it reports when the
     * application's own source files move, which is not something a deployed site should be
     * able to ask about.
     *
     * @return void
     */
    protected function routes(): void
    {
        $config = $this->container->has(Config::class)
            ? $this->container->get(Config::class)
            : null;

        $debug = (bool) ($config?->get('app.debug', false) ?? false);
        $devTool = (bool) ($config?->get('app.devTool', false) ?? false);

        if (!$debug || !$devTool) {
            return;
        }

        // Two singletons, and the split matters. The tracker holds the answer to "changed since
        // when" and must survive between requests. The handler must not: it is registered as a
        // class-string so the route stays cacheable, and the router builds those with
        // Container::make() on every request — which calls the factory each time and skips the
        // singleton cache. A stateful handler therefore compared the filesystem against itself
        // and answered "nothing changed", correctly formatted, forever.
        $this->container->singleton(
            ChangeTracker::class,
            fn(): ChangeTracker => new ChangeTracker(
                $this->container->get(ComponentLoaderRegistry::class)->resolvedLoaders(),
                // Persisted because the baseline is the only state here that a fresh process
                // cannot rebuild. Without it, every request under FPM or PHP's built-in server
                // compares the tree against itself and reports nothing — while answering 200,
                // which is the hardest kind of failure to notice.
                // The PSR interface, which is what Application registers. Asking for the
                // framework's own CacheInterface answered false on every runtime, so the baseline
                // stayed in memory and live reload worked on Swoole while doing nothing at all
                // under FPM or serve:php.
                $this->container->has(\Psr\SimpleCache\CacheInterface::class)
                    ? $this->container->get(\Psr\SimpleCache\CacheInterface::class)
                    : null
            )
        );

        $this->container->singleton(
            LiveReloadState::class,
            static fn(): LiveReloadState => new LiveReloadState()
        );

        $this->container->singleton(
            LiveReloadRoute::class,
            fn(): LiveReloadRoute => new LiveReloadRoute(
                $this->container->get(LiveReloadState::class),
                $this->container->has(LiveReloadWatcher::class)
                    ? $this->container->get(LiveReloadWatcher::class)
                    : null
            )
        );

        // Underscore-prefixed so it cannot collide with an application route of the same name,
        // and so it reads as framework territory in the routing table. A class-string, not a
        // closure: the route cache refuses to serialise a closure.
        $this->router->addRoute('GET', LiveReloadRoute::ENDPOINT, [LiveReloadRoute::class, '__invoke']);
    }

    /**
     * Start looking for changes without waiting for a request to ask.
     *
     * Handed to whichever server the application runs, through the same key the Websocket module
     * uses, because a periodic task is a thing a server can do and neither module should own the
     * server. The timer is installed per worker on WorkerStart rather than immediately: Swoole
     * forks workers after start(), and a tick registered in the parent does not reach them.
     *
     * Every worker watches, because every worker has its own subscribers. A change detected in one
     * worker cannot be pushed from another — the connections are not shared — so a single shared
     * watcher would leave every tab on every other worker waiting for an event nobody sends.
     *
     * @return void
     */
    private function watchForChanges(): void
    {
        // The service is registered whatever the interval is, because it is a service and not a
        // policy: an application with a zero interval has switched the timer off, and anything
        // else that wants to ask what changed should still be able to.
        $this->container->singleton(
            LiveReloadWatcher::class,
            fn(): LiveReloadWatcher => new LiveReloadWatcher(
                $this->container->get(ChangeTracker::class),
                $this->container->get(LiveReloadState::class),
                // Optional: a project without the Websocket module still gets live reload through
                // the polling endpoint. The broadcaster is a dependency of the feature, not of the
                // capability, and making it required would mean live reload stops working
                // entirely the moment the realtime module is switched off.
                //
                // Asked of a key the Websocket module registers when it wires itself up, not of
                // Broadcast::class: has() answers true for any autowireable class, so that check
                // was satisfied by a module that had never run.
                $this->container->has('phue.websocket.active')
                    ? $this->container->get(\Phue\Modules\Websocket\Broadcast::class)
                    : null
            )
        );

        // Resolved inside the tick, not captured. bindings() runs before routes(), so the
        // ChangeTracker this watcher needs is not registered yet at the point the ticker is
        // built — and resolving per worker is what we want anyway: Swoole forks after start(), so
        // each worker resolves into its own copy of the container and moves its own baseline.
        // A single shared baseline would let one worker consume a change and leave every tab on
        // every other worker waiting for an event nobody sends.
        $intervalMs = $this->liveReloadIntervalMs();

        if ($intervalMs <= 0) {
            return;
        }

        $container = $this->container;

        // Under phue.server.onWorkerStart, not a ticker of its own, and that is not tidiness.
        //
        // Swoole keeps exactly one WorkerStart handler: registering a second replaces the first.
        // So this module used to call $server->on('WorkerStart') from inside a ticker closure, and
        // the Websocket module's own WorkerStart registration silently took its place — the file
        // watcher never started, and live reload did nothing under Swoole while answering every
        // request normally. Which of the two survived depended on the order the modules happened to
        // initialise in, so it looked fine until it wasn't.
        //
        // The server now owns the single handler and calls everything registered here, so adding a
        // module cannot cost another module its callback. Appending rather than rebinding, because
        // singleton() replaces and the Websocket module registers under this same key.
        $existing = $container->has('phue.server.onWorkerStart')
            ? (array) $container->get('phue.server.onWorkerStart')
            : [];

        $this->container->singleton(
            'phue.server.onWorkerStart',
            static fn(): array => [
                ...array_values($existing),
                // Every worker, deliberately. Each holds different WebSocket connections with
                // different subscriptions, and a change found in one cannot be pushed from
                // another — so a single watcher would leave every tab on every other worker
                // waiting for an event nobody sends.
                static function (int $workerId) use ($intervalMs, $container): void {
                    // Swoole\Timer rather than the server: there is no tick() on a Swoole server,
                    // and discovering that at boot means a dev server that does not start.
                    \Swoole\Timer::tick($intervalMs, static function () use ($container): void {
                        $container->get(LiveReloadWatcher::class)->tick();
                    });
                },
            ]
        );
    }

    /**
     * How often the watcher looks, in milliseconds.
     *
     * The same setting the toolbar used to poll at, now spent on the server. Zero switches the
     * watcher off, which is what an application that has moved change detection into its own
     * build step wants: nothing polls, nothing ticks, and the toolbar simply never reloads.
     */
    private function liveReloadIntervalMs(): int
    {
        /** @var Config $config */
        $config = $this->container->get(Config::class);

        $devtool = (array) $config->get('devtool', []);
        $raw = $devtool['live_reload_interval_ms']
            ?? DebugToolbar::WATCH_INTERVAL_MS;

        return max(0, (int) $raw);
    }

    /**
     * Registers the profiling services and installs instrumentation.
     *
     * @return void
     */
    protected function bindings(): void
    {
        $config = $this->container->has(Config::class)
            ? $this->container->get(Config::class)
            : null;

        $debug = (bool) ($config?->get('app.debug', false) ?? false);
        $devTool = (bool) ($config?->get('app.devTool', false) ?? false);

        // The toolbar leaks request internals into the page, so it must never be
        // reachable in a non-debug context regardless of how it was configured.
        if (!$debug || !$devTool) {
            return;
        }

        // Inside the gate, not above it. The watcher needs the ChangeTracker that routes()
        // registers under the same two flags, so registering the timer outside meant a
        // production Swoole server ran this callback every second and it threw — every second —
        // for a feature nobody had switched on.
        $this->watchForChanges();

        /** @var array<string, mixed> $options */
        $options = (array) ($config?->get('devtool', []) ?? []);

        $this->registerProfilingServices();

        $profiler = $this->container->get(RequestProfiler::class);

        // Profiling is strictly a diagnostic convenience. Resolving the services it
        // decorates can legitimately fail — an application without a component directory,
        // or without a database — and a missing measurement must never take the site down.
        try {
            $this->instrumentSsrTransport($profiler);
        } catch (\Throwable $e) {
            $this->warn($profiler, 'SSR transport instrumentation skipped', $e);
        }

        try {
            $this->instrumentComponentLoaders($profiler);
        } catch (\Throwable $e) {
            $this->warn($profiler, 'Component loader instrumentation skipped', $e);
        }

        try {
            $this->instrumentComponentLoading($profiler);
        } catch (\Throwable $e) {
            $this->warn($profiler, 'Component loader registry instrumentation skipped', $e);
        }

        try {
            $this->instrumentDatabase($profiler);
        } catch (\Throwable $e) {
            $this->warn($profiler, 'Database instrumentation skipped', $e);
        }

        $this->container->get(\Phue\Core\Application::class)
            ->use(DevToolMiddleware::class);
    }

    /**
     * Record a non-fatal instrumentation failure in the report and the log.
     *
     * @param RequestProfiler $profiler Collector receiving the note.
     * @param string $message Human readable reason.
     * @param \Throwable $exception Underlying failure.
     * @return void
     */
    private function warn(RequestProfiler $profiler, string $message, \Throwable $exception): void
    {
        // Persistent: this describes the instrumentation that could not be installed, which is
        // a fact about the process rather than about any one request.
        $profiler->addDiagnostic($message, ['exception' => $exception->getMessage()], true);

        $logger = $this->container->has(LoggerInterface::class)
            ? $this->container->get(LoggerInterface::class)
            : null;

        $logger?->warning($message, ['exception' => $exception]);
    }

    /**
     * Bind the collector, the toolbar and the middleware as application singletons.
     *
     * Singleton scope is required, not incidental: the decorators are resolved from the
     * container during a request while the middleware was resolved from the same graph,
     * and both must observe the same span list.
     *
     * @return void
     */
    private function registerProfilingServices(): void
    {
        $options = $this->options();

        $this->container->singleton(Stopwatch::class, static fn(): Stopwatch => new Stopwatch());

        $this->container->singleton(
            RequestProfiler::class,
            fn(): RequestProfiler => new RequestProfiler($this->container->get(Stopwatch::class))
        );

        $this->container->singleton(DebugToolbar::class, static fn(): DebugToolbar => new DebugToolbar());

        $this->container->singleton(
            DevToolMiddleware::class,
            fn(): DevToolMiddleware => new DevToolMiddleware(
                $this->container->get(RequestProfiler::class),
                $this->container->get(DebugToolbar::class),
                $options
            )
        );
    }

    /**
     * Replace the SSR transport with a profiling decorator.
     *
     * The real client is resolved first and then re-bound behind the decorator. Resolving
     * first is what keeps the original transport wiring in Application where it belongs,
     * instead of duplicating its constructor arguments here.
     *
     * @param RequestProfiler $profiler Collector receiving the spans.
     * @return void
     */
    private function instrumentSsrTransport(RequestProfiler $profiler): void
    {
        $clientClass = \Phue\Core\Ssr\UnixSsrClient::class;

        if (!$this->container->has($clientClass)) {
            return;
        }

        $real = $this->container->get($clientClass);

        if ($real instanceof ProfilingSsrClient) {
            return;
        }

        $this->container->singleton(
            $clientClass,
            static fn(): SsrClient => new ProfilingSsrClient($real, $profiler)
        );
    }

    /**
     * Replace the component loader registry with a profiling subclass.
     *
     * The registry is the funnel for everything the renderer routes through it, and the
     * loader decoration below covers the paths that bypass it. Both are needed: a component
     * hash can be minted from either side, and missing the hot one makes the report lie.
     *
     * @param RequestProfiler $profiler Collector receiving the spans.
     * @return void
     */
    private function instrumentComponentLoading(RequestProfiler $profiler): void
    {
        if (!$this->container->has(ComponentLoaderRegistry::class)) {
            return;
        }

        $real = $this->container->get(ComponentLoaderRegistry::class);

        if ($real instanceof ProfilingComponentLoaderRegistry) {
            return;
        }

        $logger = $this->container->has(LoggerInterface::class)
            ? $this->container->get(LoggerInterface::class)
            : null;

        $decorator = new ProfilingComponentLoaderRegistry($logger, $profiler);

        // The copied loaders are the closures the framework already registered, and those
        // closures capture the ORIGINAL registry and call setRegistry() on themselves. Left
        // alone, invoking them would re-point the loader back at the unprofiled registry
        // and the loader's own recursive child resolution would bypass the decorator
        // entirely — a profiler that silently reports zero calls for the exact operation it
        // exists to measure. Re-pointing after invocation keeps the decorator in the loop.
        foreach ($real->getLoaders() as $candidate) {
            $decorator->addLoader(static function () use ($candidate, $decorator): ?PhueComponentLoader {
                $loader = is_callable($candidate) ? $candidate() : $candidate;

                if ($loader instanceof PhueComponentLoader) {
                    $loader->setRegistry($decorator);
                }

                return $loader;
            });
        }

        $this->container->singleton(
            ComponentLoaderRegistry::class,
            static fn(): ComponentLoaderRegistry => $decorator
        );

        // The loader bindings stay untouched: they keep their own lifecycle and are only
        // re-pointed at the decorator so that buildFor() resolves child hashes through it.
        foreach ([PhueComponentLoader::class, 'phui.loader'] as $loaderId) {
            if (!$this->container->has($loaderId)) {
                continue;
            }

            $loader = $this->container->get($loaderId);
            if ($loader instanceof PhueComponentLoader) {
                $loader->setRegistry($decorator);
            }
        }
    }

    /**
     * Replace the component loaders with profiling subclasses.
     *
     * The renderer mints module hashes straight off the loader (see
     * ComponentRenderer::renderComponentAsModule), so the registry decorator alone never
     * sees that work. Each replacement is constructed from the real loader's own identity
     * and inherits its memoization tables, so measurement costs no throughput.
     *
     * @param RequestProfiler $profiler Collector receiving the spans.
     * @return void
     */
    private function instrumentComponentLoaders(RequestProfiler $profiler): void
    {
        if (!$this->container->has(TemplateCompilerBridge::class)) {
            return;
        }

        $logger = $this->container->has(LoggerInterface::class)
            ? $this->container->get(LoggerInterface::class)
            : null;

        $cache = $this->container->has(CacheInterface::class)
            ? $this->container->get(CacheInterface::class)
            : null;

        $bridge = $this->container->get(TemplateCompilerBridge::class);

        foreach ([PhueComponentLoader::class, 'phui.loader'] as $loaderId) {
            // Each binding is isolated: an application with no PhUI directory must still
            // get its own loader instrumented, and one missing binding must not silently
            // take the rest of the instrumentation down with it.
            try {
                if (!$this->container->has($loaderId)) {
                    continue;
                }

                $real = $this->container->get($loaderId);

                if ($real instanceof ProfilingComponentLoader) {
                    continue;
                }

                $replacement = new ProfilingComponentLoader(
                    componentsDir: $real->getComponentsDir(),
                    persistentCache: $cache,
                    viewsCacheDir: null,
                    compilerBridge: $bridge,
                    profiler: $profiler,
                    logger: $logger,
                    source: $real,
                    container: $this->container
                );

                $this->container->singleton(
                    $loaderId,
                    static fn(): PhueComponentLoader => $replacement
                );
            } catch (\Throwable $e) {
                $this->warn($profiler, "Component loader '{$loaderId}' instrumentation skipped", $e);
            }
        }
    }

    /**
     * Replace the database driver with a profiling decorator.
     *
     * The driver sits behind DriverInterface, so it can be decorated cleanly without any
     * change to the database core. The connection is re-created from the same config keys
     * Application::bootstrapDatabase() reads, which is the only wiring duplicated here.
     *
     * @param RequestProfiler $profiler Collector receiving the spans.
     * @return void
     */
    private function instrumentDatabase(RequestProfiler $profiler): void
    {
        if (!$this->container->has(\Phue\Core\Database\Connection::class)) {
            return;
        }

        $config = $this->container->get(Config::class);

        /** @var string|null $default */
        $default = $config->get('database.default');
        if (!is_string($default) || $default === '') {
            return;
        }

        $dbConfig = $config->get("database.connections.{$default}");
        if (!is_array($dbConfig)) {
            return;
        }

        $redact = $this->redactsSqlLiterals();

        $this->container->singleton(
            \Phue\Core\Database\Connection::class,
            static fn(): \Phue\Core\Database\Connection => new ProfilingConnection($dbConfig, $profiler, $redact)
        );

        $this->container->alias('db', \Phue\Core\Database\Connection::class);
    }

    /**
     * Whether recorded SQL has its quoted literals removed.
     *
     * Redaction is the default because a prepared statement carries only placeholders:
     * anything quoted in the recorded text arrived through interpolation, which is the one
     * path that can put a real row of user data into a rendered page. A deployment that
     * needs the exact text can opt out through config.
     *
     * @return bool
     */
    private function redactsSqlLiterals(): bool
    {
        $mode = (string) ($this->options()['sql_literals'] ?? 'redact');

        return $mode !== 'raw';
    }

    /**
     * Toolbar panel options from config/devtool.php.
     *
     * @return array<string, mixed>
     */
    private function options(): array
    {
        if (!$this->container->has(Config::class)) {
            return [];
        }

        /** @var array<string, mixed> $options */
        $options = (array) $this->container->get(Config::class)->get('devtool', []);

        return $options;
    }
}
