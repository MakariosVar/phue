<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Routing;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Core\Routing\Loader\AttributeRouteLoader;
use Phue\Tests\Support\RestoresActiveRecordConnection;

/**
 * The route cache may only ever ADD a speed-up. It may never change what routes
 * exist, because a cache that drops a route is indistinguishable from an
 * application that was never configured correctly.
 *
 * Two failures shaped this file.
 *
 * First: core framework controllers (AssetsController, HealthController) were
 * moved OUT of the cache-miss branch and registered BEFORE the cache is
 * consulted, which reads as a fix for "the cache might not contain the core
 * routes". It was the opposite — Router::registerCachedRoutes() does
 * `$this->routes = $routes`, a wholesale replacement, so those registrations
 * are discarded the moment a cache loads. The suite stayed green, because
 * nothing asserted the invariant.
 *
 * Second, and worse: the validator that was added to catch it checked for a
 * literal "/health" or "/healthcheck" path. The framework actually registers
 * /health/live and /health/ready. Neither matched, so every valid cache was
 * rejected and route caching was silently disabled for every application —
 * correctly, because a rejected cache falls back to a full scan, so nothing
 * errored and nothing merely looked slow.
 *
 * A guard that rejects everything is not safe. It is off.
 *
 * @package Phue\Tests\Core\Routing
 */
#[Group('unit')]
final class RouteCacheSafetyTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = sys_get_temp_dir() . '/phue_routecache_' . uniqid();
        mkdir($this->baseDir . '/config', 0755, true);
        mkdir($this->baseDir . '/storage/cache', 0755, true);

        // Caching ON, no modules: the subject is the core route table.
        file_put_contents(
            $this->baseDir . '/config/app.php',
            "<?php\nreturn [\n"
            . "    'name' => 'probe',\n"
            . "    'debug' => false,\n"
            . "    'devTool' => false,\n"
            . "    'routeCache' => true,\n"
            . "    'modules' => [],\n"
            . "];\n"
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_dir($this->baseDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->baseDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                $item->isDir() && !$item->isLink()
                    ? rmdir($item->getPathname())
                    : unlink($item->getPathname());
            }

            @rmdir($this->baseDir);
        }
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function loader(): AttributeRouteLoader
    {
        return new AttributeRouteLoader();
    }

    private function app(?string $baseDir = null): Application
    {
        $app = new Application($baseDir ?? $this->baseDir, debug: false);

        $this->isolateApplicationDatabase($app);

        return $app;
    }

    /**
     * The framework's own routes, as the cache actually sees them.
     *
     * Note the health paths: the framework registers /health/live and
     * /health/ready, not /health. Fixtures must use the real ones or the
     * validator rejects them and the test proves nothing.
     *
     * @return list<array<string, mixed>>
     */
    private function coreRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/health/live',
                'regex' => '#^/health/live$#',
                'handler' => ['Phue\\Core\\Health\\HealthController', 'live'],
                'paramCount' => 0,
                'middleware' => [],
            ],
            [
                'method' => 'GET',
                'path' => '/health/ready',
                'regex' => '#^/health/ready$#',
                'handler' => ['Phue\\Core\\Health\\HealthController', 'ready'],
                'paramCount' => 0,
                'middleware' => [],
            ],
            [
                'method' => 'GET',
                'path' => '/assets/{path}',
                'regex' => '#^/assets/(?P<path>.*)$#',
                'handler' => ['Phue\\Core\\Asset\\AssetsController', 'serve'],
                'paramCount' => 1,
                'middleware' => [],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $extra
     * @return list<array<string, mixed>>
     */
    private function withCore(array $extra): array
    {
        return array_merge($this->coreRoutes(), $extra);
    }

    /**
     * @param array<string, mixed> $route
     * @return array<string, mixed>
     */
    private function route(string $method, string $path, mixed $handler = 'strlen'): array
    {
        return [
            'method' => $method,
            'path' => $path,
            'regex' => '#^' . preg_quote($path, '#') . '$#',
            'handler' => $handler,
            'paramCount' => substr_count($path, '{'),
            'middleware' => [],
        ];
    }

    private function writeCache(array $routes, string $name = 'routes.php', ?string $fingerprint = null): string
    {
        $file = $this->baseDir . '/storage/cache/' . $name;

        file_put_contents(
            $file,
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
                'scannedAt' => time(),
                'fingerprint' => $fingerprint ?? $this->fingerprint(),
                'routes' => $routes,
            ], true) . ";\n"
        );

        return $file;
    }

    /**
     * The framework's required paths, derived from the controllers the way
     * Application::cacheRoutes() derives them. Hardcoding strings here would let
     * the fixture drift from reality, which is precisely the defect this file
     * was written for.
     *
     * @return list<string>
     */
    private function requiredPaths(): array
    {
        $paths = [];

        foreach (
            [
                \Phue\Core\Asset\AssetsController::class,
                \Phue\Core\Health\HealthController::class,
            ] as $controller
        ) {
            foreach ($this->loader()->load($controller) as $route) {
                if (isset($route['path'])) {
                    $paths[] = $route['path'];
                }
            }
        }

        return $paths;
    }

    private function fingerprint(?string $baseDir = null): string
    {
        return $this->loader()->fingerprint($baseDir ?? $this->baseDir, []);
    }

    /**
     * Loads exactly the way Application does, so these tests exercise the real
     * contract rather than a laxer one.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function loadLikeRuntime(string $file, ?string $baseDir = null): ?array
    {
        return $this->loader()->loadCached(
            $file,
            $this->fingerprint($baseDir),
            $this->requiredPaths()
        );
    }

    /**
     * @return list<string>
     */
    private function pathsOf(Application $app): array
    {
        $paths = [];

        foreach ($app->getRouter()->getCompiledRoutes() as $route) {
            $paths[] = $route['method'] . ' ' . $route['path'];
        }

        sort($paths);

        return $paths;
    }

    // ------------------------------------------------------------------
    // The guard must accept what is real
    // ------------------------------------------------------------------

    /**
     * The single most important assertion in this file: the real application
     * route table must be accepted by its own cache.
     *
     * It was not. The validator looked for a path the framework does not
     * register, so 23 saved routes came back as null and route caching was off
     * everywhere — silently, because the fallback is a correct-but-slow scan.
     */
    public function testRealApplicationRouteTableIsAcceptedByTheCache(): void
    {
        $app = $this->app('/app');
        $routes = $app->getRouter()->getCompiledRoutes();

        $this->assertNotEmpty($routes);

        $probe = $this->baseDir . '/storage/cache/real-probe.php';
        $this->loader()->saveCache($probe, $routes, $this->fingerprint('/app'));

        $loaded = $this->loadLikeRuntime($probe, '/app');

        $this->assertIsArray(
            $loaded,
            'The real application route table must be accepted by its own cache. A validator that '
            . 'rejects every valid table is not a safety feature, it is a disabled feature.'
        );
        $this->assertSame(count($routes), count($loaded));
    }

    /**
     * Narrower form of the same defect: the health route that exists is the one
     * that must satisfy the check.
     */
    public function testHealthRouteThatActuallyExistsIsAcceptedByTheValidator(): void
    {
        $routes = $this->app('/app')->getRouter()->getCompiledRoutes();

        $health = array_values(array_filter(
            $routes,
            static fn(array $route): bool => str_starts_with($route['path'], '/health')
        ));

        $this->assertNotEmpty(
            $health,
            'The framework must expose a health route, otherwise cache validation has nothing to check.'
        );

        $probe = $this->baseDir . '/storage/cache/health-probe.php';
        $this->loader()->saveCache($probe, $routes, $this->fingerprint('/app'));

        $this->assertIsArray($this->loadLikeRuntime($probe, '/app'));
    }

    /**
     * The happy path, in isolation from any real application.
     */
    public function testValidCacheRoundTripsThroughSaveAndLoad(): void
    {
        $cacheFile = $this->writeCache($this->withCore([
            $this->route('GET', '/users/{id:int}', ['App\\Controllers\\UserController', 'show']),
            $this->route('POST', '/users', ['App\\Controllers\\UserController', 'store']),
        ]));

        $routes = $this->loadLikeRuntime($cacheFile);

        $this->assertIsArray($routes);
        $this->assertCount(5, $routes);

        $content = (string) file_get_contents($cacheFile);
        $this->assertStringStartsWith('<?php', $content);
        $this->assertStringContainsString('declare(strict_types=1);', $content);
    }

    // ------------------------------------------------------------------
    // The guard must reject what is not
    // ------------------------------------------------------------------

    /**
     * A cache that is missing the asset route makes every stylesheet and script
     * 404, which presents as an unstyled, non-functional page rather than an
     * error. AssetsController is a framework route, so no application author can
     * be expected to notice it went missing.
     */
    public function testAssetPipelineSurvivesACacheThatOmitsIt(): void
    {
        $this->writeCache([$this->route('GET', '/only-this')]);

        // The file must actually exist, or a 404 would be the correct answer and
        // this test would pass for the wrong reason.
        mkdir($this->baseDir . '/storage/assets/vendor/probe', 0755, true);
        file_put_contents($this->baseDir . '/storage/assets/vendor/probe/app.js', 'const a = 1;');

        $app = $this->app();

        $this->assertContains(
            'GET /assets/{path}',
            $this->pathsOf($app),
            'The asset route must survive a cache load. Router::registerCachedRoutes() replaces the '
            . 'whole table, so registering core controllers before the cache is loaded does nothing.'
        );

        $response = $app->handle(new Request('GET', '/assets/vendor/probe/app.js'));

        $this->assertSame(
            200,
            $response->getStatusCode(),
            '/assets/* 404s when the cache does not carry AssetsController.'
        );
    }

    /**
     * The health endpoint is what deployment tooling polls to decide whether to
     * roll traffic. A cache that hides it turns a healthy instance into one that
     * gets killed.
     */
    public function testHealthRouteSurvivesACacheThatOmitsIt(): void
    {
        $this->writeCache([$this->route('GET', '/only-this')]);

        $paths = $this->pathsOf($this->app());

        $this->assertContains(
            'GET /health/live',
            $paths,
            'The health route must survive a cache load.'
        );
        $this->assertContains('GET /health/ready', $paths);
    }

    /**
     * An empty route list is the degenerate case and needs no fixture. An
     * application whose route table emptied on boot has no 404 handler, no asset
     * pipeline and no health check, and fails in a way that is very hard to
     * attribute.
     */
    public function testAnEmptyRouteListIsTreatedAsSuspiciousNotAuthoritative(): void
    {
        $this->writeCache([]);

        $this->assertNotSame(
            [],
            $this->pathsOf($this->app()),
            'A cache containing zero routes must not be trusted.'
        );
    }

    /**
     * THE invariant, stated once: loading a cache must reproduce the live route
     * table exactly. Booting twice — once with no cache, once with that exact
     * table as the cache — must yield identical routing.
     */
    public function testLoadedCacheReproducesTheLiveRouteTableExactly(): void
    {
        @unlink($this->baseDir . '/storage/cache/routes.php');

        $uncached = $this->pathsOf($this->app());
        $this->assertNotEmpty($uncached, 'Baseline route table must not be empty.');

        $this->writeCache($this->app()->getRouter()->getCompiledRoutes());

        $this->assertSame(
            $uncached,
            $this->pathsOf($this->app()),
            'A route cache must reproduce the live route table exactly. Any difference means routes '
            . 'exist or vanish depending on whether a cache file happens to be on disk.'
        );
    }

    // ------------------------------------------------------------------
    // Serialisation safety
    // ------------------------------------------------------------------

    /**
     * A Closure handler cannot be serialised. var_export renders one as
     * \Closure::__set_state(array()), and requiring that file is a fatal error
     * on the NEXT boot — not the one that wrote it. The failure lands on a
     * request with nothing to do with the code that caused it.
     *
     * Refusing loudly is right: the alternative is silently dropping the route,
     * which becomes a 404 nobody connects to a cache file.
     */
    public function testClosureHandlerIsRefusedLoudlyInsteadOfPoisoningTheCache(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/[Cc]losure/');

        $this->loader()->saveCache(
            $this->baseDir . '/storage/cache/routes.php',
            [$this->route('GET', '/closure-route', static fn(): string => 'ok')]
        );
    }

    /**
     * The refusal must happen BEFORE anything is written, so a failed save
     * cannot leave a half-updated cache behind.
     */
    public function testRefusedSaveLeavesNoCacheOrTempFileBehind(): void
    {
        try {
            $this->loader()->saveCache(
                $this->baseDir . '/storage/cache/routes.php',
                [$this->route('GET', '/closure-route', static fn(): string => 'ok')]
            );
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->assertSame(
            [],
            glob($this->baseDir . '/storage/cache/*') ?: [],
            'A refused save must leave neither a cache file nor a temporary one behind.'
        );
    }

    /**
     * The same trap one level deeper: a Closure as the object half of an
     * [obj, method] handler.
     */
    public function testClosureNestedInAnArrayHandlerIsAlsoRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->loader()->saveCache(
            $this->baseDir . '/storage/cache/routes.php',
            [$this->route('GET', '/nested-closure', [static fn(): string => 'ok', 'method'])]
        );
    }

    // ------------------------------------------------------------------
    // Corrupt and truncated files
    // ------------------------------------------------------------------

    /**
     * A truncated file is what a crash mid-write produces. loadCached() runs on
     * every boot, so a corrupt file must degrade to a scan rather than fatal.
     */
    public function testTruncatedCacheFileDegradesToScanInsteadOfFataling(): void
    {
        $cacheFile = $this->writeCache($this->withCore([$this->route('GET', '/intact')]));

        $contents = (string) file_get_contents($cacheFile);
        file_put_contents($cacheFile, substr($contents, 0, (int) (strlen($contents) * 0.6)));

        $this->assertNull($this->loadLikeRuntime($cacheFile));
    }

    /**
     * Garbage that happens to be valid PHP but is not a route cache.
     */
    public function testCacheFileReturningSomethingOtherThanARouteArrayIsIgnored(): void
    {
        $variants = [
            "<?php\nreturn 'not an array';",
            "<?php\nreturn null;",
            "<?php\nreturn [];",
            "<?php\nreturn ['routes' => 'not a list'];",
            "<?php\nreturn ['scannedAt' => 1];",
        ];

        foreach ($variants as $index => $contents) {
            $file = $this->baseDir . '/storage/cache/garbage-' . $index . '.php';
            file_put_contents($file, $contents);

            $this->assertNull(
                $this->loader()->loadCached($file),
                "Variant {$index} must be rejected, not loaded as a route table."
            );
        }
    }

    /**
     * Writes must be atomic. file_put_contents with LOCK_EX serialises writers
     * but tells the reader nothing: a reader can open the file after the
     * truncate and before the write completes. Writing a sibling temp file and
     * renaming means the target path only ever names a complete file.
     *
     * Verified structurally — interleaving two processes to catch the window is
     * not something a unit test can promise. What it does assert is the
     * mechanism: a temp file is used, it is renamed away, nothing is left.
     */
    public function testSaveIsAtomicAndLeavesNoTemporaryFiles(): void
    {
        $this->loader()->saveCache(
            $this->baseDir . '/storage/cache/routes.php',
            $this->withCore([$this->route('GET', '/atomic')]),
            $this->fingerprint()
        );

        $this->assertFileExists($this->baseDir . '/storage/cache/routes.php');
        $this->assertSame(
            [],
            glob($this->baseDir . '/storage/cache/*.tmp.*') ?: [],
            'saveCache() must rename its temp file into place. A leftover .tmp file means the '
            . 'rename failed and the previous cache is still in force.'
        );
    }

    /**
     * Overwriting must replace completely. A reader holding the old cache must
     * never get a mixture of old and new.
     */
    public function testOverwritingAnExistingCacheReplacesItEntirely(): void
    {
        $cacheFile = $this->baseDir . '/storage/cache/routes.php';

        $this->loader()->saveCache(
            $cacheFile,
            $this->withCore([$this->route('GET', '/old-route')]),
            $this->fingerprint()
        );

        $this->loader()->saveCache(
            $cacheFile,
            $this->withCore([$this->route('GET', '/new-route')]),
            $this->fingerprint()
        );

        $loaded = $this->loadLikeRuntime($cacheFile);

        $this->assertIsArray($loaded);
        $this->assertCount(4, $loaded, 'A rewritten cache must contain the core routes plus exactly the new one.');
        $this->assertStringNotContainsString('old-route', (string) file_get_contents($cacheFile));
    }

    // ------------------------------------------------------------------
    // Staleness — 1.5.3
    // ------------------------------------------------------------------

    /**
     * A cache with no fingerprint at all is from before fingerprints existed, or
     * was hand-written. It cannot be trusted, because there is no way to tell
     * whether it matches the current controllers.
     */
    public function testCacheWithoutAFingerprintIsRejected(): void
    {
        $file = $this->baseDir . '/storage/cache/routes.php';

        file_put_contents(
            $file,
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
                'scannedAt' => time(),
                'routes' => $this->withCore([]),
            ], true) . ";\n"
        );

        $this->assertNull(
            $this->loadLikeRuntime($file),
            'A cache with no fingerprint cannot be matched against current sources, so it must be '
            . 'ignored. Otherwise every deployment that predates fingerprinting keeps serving a '
            . 'route table that no longer describes the application.'
        );
    }

    /**
     * Isolates the empty-route guard from the required-paths guard.
     *
     * With requiredPaths supplied, an empty route list is already rejected,
     * which means a test that only exercises the normal path cannot tell whether
     * the empty check exists at all. Here the caller declares no requirements,
     * so this is the empty guard alone doing the work.
     */
    public function testEmptyRouteListIsRejectedEvenWithNoRequirementsDeclared(): void
    {
        $file = $this->baseDir . '/storage/cache/routes.php';
        $this->writeCache([], 'routes.php');

        $this->assertNull(
            $this->loader()->loadCached($file, ''),
            'A cache with zero routes must be rejected even when no required paths are declared. '
            . 'An application whose route table emptied on boot has no 404 handler, no asset '
            . 'pipeline and no health check, and fails in a way that is very hard to attribute.'
        );
    }

    /**
     * THE staleness failure this was added for.
     *
     * Before fingerprints, changing a controller had no effect on the cache at
     * all. The route was added, the cache was not invalidated, and the new route
     * simply never appeared — no error, no log, just a 404 that persists across
     * restarts until someone happens to run cache:clear.
     */
    public function testAddingAControllerInvalidatesTheCache(): void
    {
        $controllers = $this->baseDir . '/App/Controllers';
        mkdir($controllers, 0755, true);

        $before = $this->fingerprint();

        $this->writeCache($this->withCore([$this->route('GET', '/before')]), 'routes.php', $before);
        $this->assertIsArray(
            $this->loadLikeRuntime($this->baseDir . '/storage/cache/routes.php'),
            'A cache matching current sources must be accepted.'
        );

        file_put_contents($controllers . '/NewController.php', "<?php\n// added\n");

        $this->assertNotSame(
            $before,
            $this->fingerprint(),
            'Adding a controller must change the fingerprint, or a new route can never take effect.'
        );

        $this->assertNull(
            $this->loadLikeRuntime($this->baseDir . '/storage/cache/routes.php'),
            'A cache written before a controller was added must be rejected. Serving it means the '
            . 'new route 404s forever, with no indication that a cache is involved.'
        );
    }

    /**
     * The same for a module being added. Modules register routes programmatically,
     * so a fingerprint that only covered controller files would miss them.
     */
    public function testChangingTheModuleListInvalidatesTheCache(): void
    {
        $loader = $this->loader();

        $this->assertNotSame(
            $loader->fingerprint($this->baseDir, []),
            $loader->fingerprint($this->baseDir, ['App\\SomeModule']),
            'Adding a module must change the fingerprint. Modules register routes programmatically, '
            . 'so a fingerprint covering only controller files would miss them.'
        );

        $this->assertSame(
            $loader->fingerprint($this->baseDir, ['A', 'B']),
            $loader->fingerprint($this->baseDir, ['B', 'A']),
            'Module ORDER must not change the fingerprint, or the cache would be invalidated on '
            . 'every boot for an application whose config is merely ordered differently.'
        );
    }

    /**
     * The fingerprint must be stable when nothing changed, or every request
     * would rescan and the cache would be worthless.
     */
    public function testFingerprintIsStableAcrossCallsAndPathFormats(): void
    {
        $loader = $this->loader();

        $this->assertSame(
            $loader->fingerprint($this->baseDir, []),
            $loader->fingerprint($this->baseDir, []),
            'The fingerprint must be deterministic within a request.'
        );
        $this->assertSame(
            $loader->fingerprint($this->baseDir . '/', []),
            $loader->fingerprint($this->baseDir, []),
            'A trailing slash must not change the fingerprint.'
        );
    }

    /**
     * With the stale cache rejected, the application must still come up complete.
     * A cache that is ignored has to be replaced, not merely skipped.
     */
    public function testStaleCacheIsReplacedOnNextBoot(): void
    {
        $this->writeCache(
            $this->withCore([$this->route('GET', '/from-stale-cache')]),
            'routes.php',
            'a-fingerprint-from-another-deployment'
        );

        $app = $this->app();

        $this->assertContains('GET /health/live', $this->pathsOf($app));
        $this->assertContains('GET /assets/{path}', $this->pathsOf($app));
        $this->assertNotContains(
            'GET /from-stale-cache',
            $this->pathsOf($app),
            'A route from a rejected cache must not reach the router.'
        );

        // And the file on disk is now current.
        $this->assertIsArray(
            $this->loadLikeRuntime($this->baseDir . '/storage/cache/routes.php'),
            'A rejected cache must be rewritten, not left stale for the next boot to reject again.'
        );
    }
}
