<?php

declare(strict_types=1);

namespace Phue\Tests\Production;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Application;
use Phue\Core\Env;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Tests\Support\RestoresActiveRecordConnection;

/**
 * The framework must fail closed when it has been told nothing.
 *
 * Every other test in this suite boots the repository, where a .env exists and
 * APP_DEBUG happens to be false. That makes the suite blind to the default:
 * a fresh clone, a CI runner, or a container started without --env-file all get
 * the built-in default instead, and nothing asserts what that is.
 *
 * This class therefore builds an application on a throwaway base path with no
 * .env, and asserts the defaults directly. The assertions are on the values,
 * not on the mechanism — the mechanism is a different concern and the defaults
 * are the thing that ships.
 *
 * @package Phue\Tests\Production
 */
#[Group('unit')]
final class FailClosedDefaultsTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();

        // No .env is written here on purpose. An app that is told nothing must
        // get the built-in defaults, which is what this suite pins.
        $this->baseDir = sys_get_temp_dir() . '/phue_defaults_' . uniqid();
        mkdir($this->baseDir . '/config', 0755, true);
        mkdir($this->baseDir . '/storage', 0755, true);

        // The real config directory, verbatim. The defaults under test live in
        // config/app.php; copying it in means these assertions run against the
        // artefact that actually ships rather than against a fixture that can
        // drift away from it.
        $this->copyDirectory(dirname(__DIR__, 2) . '/config', $this->baseDir . '/config');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (!is_dir($this->baseDir)) {
            return;
        }

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

    private function copyDirectory(string $from, string $to): void
    {
        if (!is_dir($from)) {
            return;
        }

        @mkdir($to, 0755, true);

        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $source = $from . '/' . $entry;
            $target = $to . '/' . $entry;

            is_dir($source) ? $this->copyDirectory($source, $target) : copy($source, $target);
        }
    }

    /**
     * Runs a callback with the given keys absent from every environment source.
     *
     * Without this, the assertion is theatre. The repository's own .env sets
     * APP_DEBUG=false, and Env::get() consults Env::$variables and then getenv()
     * before falling through to the default in config/app.php. Since every other
     * test in the suite has already loaded that file, asserting the default here
     * would pass even if the default were true — a green light wired to the wrong
     * switch. The process environment has to be cleared to test a default.
     *
     * This removes ONLY the requested keys, from all three sources, and puts
     * them back verbatim. Env::reset() is deliberately not used: it clears
     * Env::$variables, after which values fall through to getenv(), where a
     * boolean has already been stringified to "1" by putenv(). Env::get()'s
     * match handles 'true' and 'false' but not '1' and '0', so every boolean in
     * the suite would come back as a string and blow up the next strict type
     * hint — SchiiConfig::__construct(bool $enabled) is the first casualty.
     *
     * @param list<string> $keys
     */
    private function withCleanEnvironment(array $keys, callable $callback): void
    {
        $reflection = new \ReflectionClass(Env::class);
        $property = $reflection->getProperty('variables');
        $variables = $property->getValue();
        $missing = [];

        foreach ($variables as $key => $value) {
            if (in_array($key, $keys, true)) {
                $missing[$key] = $value;
                unset($variables[$key]);
            }
        }

        $property->setValue(null, $variables);

        $savedEnv = [];
        $savedServer = [];
        $savedGetenv = [];

        foreach ($keys as $key) {
            $savedEnv[$key] = $_ENV[$key] ?? null;
            $savedServer[$key] = $_SERVER[$key] ?? null;
            $savedGetenv[$key] = getenv($key);

            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        try {
            $callback();
        } finally {
            foreach ($missing as $key => $value) {
                $variables[$key] = $value;
            }

            $property->setValue(null, $variables);

            foreach ($keys as $key) {
                if ($savedGetenv[$key] !== false) {
                    putenv($key . '=' . $savedGetenv[$key]);
                }
                if ($savedEnv[$key] !== null) {
                    $_ENV[$key] = $savedEnv[$key];
                }
                if ($savedServer[$key] !== null) {
                    $_SERVER[$key] = $savedServer[$key];
                }
            }
        }
    }

    private function app(): Application
    {
        $app = new Application($this->baseDir);

        // The temp base path carries a COPY of the repository's config/, so
        // config/database.php runs here too. Without this, env('DB_CONNECTION')
        // answers from the .env an earlier test loaded and the boot hands the
        // global Active Record connection to the real MariaDB.
        $this->isolateApplicationDatabase($app);

        return $app;
    }

    public function testDebugDefaultsToFalseWithoutAnEnvFile(): void
    {
        $this->withCleanEnvironment(['APP_DEBUG', 'APP_DEV_TOOL'], function (): void {
            $app = $this->app();

            $this->assertFalse(
                $app->getConfig()->get('app.debug'),
                'app.debug must default to false. With nothing configured, an application used '
                . 'to answer anonymous 500s with a stack trace and absolute filesystem paths.'
            );
            $this->assertFalse($app->isDebug());
        });
    }

    public function testDevToolDefaultsToFalseWithoutAnEnvFile(): void
    {
        $this->withCleanEnvironment(['APP_DEBUG', 'APP_DEV_TOOL'], function (): void {
            $app = $this->app();

            $this->assertFalse(
                $app->getConfig()->get('app.devTool'),
                'app.devTool must default to false. It gates DevToolsModule, which runs a '
                . 'profiler and a debug toolbar on every request.'
            );
        });
    }

    /**
     * The end-to-end version of the same claim: the error response body must not
     * contain the exception message, a stack trace, or the base path.
     */
    public function testUnhandledExceptionDoesNotLeakInternalsByDefault(): void
    {
        // Must run with the env cleaned, like its siblings above. Without this it
        // inherits whatever APP_DEBUG a previously executed test left in Env, the
        // app comes up in debug mode, and the handler echoes the exception message
        // — which is exactly the leak this test exists to catch, so the pollution
        // turns a real failure into a phantom one (and hides a real one).
        $this->withCleanEnvironment(['APP_DEBUG', 'APP_DEV_TOOL'], function (): void {
            $app = $this->app();

            $app->getRouter()->get('/boom', static function (): Response {
                throw new \RuntimeException('connection to pgsql://user:hunter2@10.0.0.5 failed');
            });

            $response = $app->handle(new Request('GET', '/boom', [], [], ['Accept' => 'application/json']));
            $body = (string) $response->getContent();

            $this->assertStringNotContainsString('hunter2', $body, 'Credentials leaked into a response.');
            $this->assertStringNotContainsString('10.0.0.5', $body, 'Internal host leaked into a response.');
            $this->assertStringNotContainsString($this->baseDir, $body, 'Filesystem path leaked into a response.');
            $this->assertStringNotContainsString('RuntimeException', $body, 'Exception class leaked into a response.');
            $this->assertStringNotContainsString('.php', $body, 'A file name leaked into a response.');
        });
    }

    /**
     * The flip side. If debug can never expose anything, the flag is useless and
     * nobody will ever turn it on, so the verbose path has to keep working.
     */
    public function testDebugOptInStillExposesTheMessage(): void
    {
        $app = new Application($this->baseDir, debug: true);
        $this->isolateApplicationDatabase($app);

        $this->assertTrue($app->isDebug());

        $app->getRouter()->get('/boom', static function (): Response {
            throw new \RuntimeException('diagnostic detail');
        });

        $response = $app->handle(new Request('GET', '/boom', [], [], ['Accept' => 'application/json']));

        $this->assertStringContainsString('diagnostic detail', (string) $response->getContent());
    }

    /**
     * Route caching is orthogonal to debugging.
     *
     * The default used to be !debug, which coupled two unrelated concerns: it
     * meant that turning debug on to read a log silently disabled routing
     * performance, and it meant that choosing the safe debug default switched
     * cache writing on. A fresh application must not write a cache file nobody
     * asked for.
     */
    public function testRouteCacheIsOffByDefaultAndIndependentOfDebug(): void
    {
        $app = new Application($this->baseDir, debug: false);
        $this->isolateApplicationDatabase($app);

        $this->assertFalse(
            $app->getConfig()->get('app.routeCache', false),
            'Route caching must be opt-in, not derived from the debug flag.'
        );

        $this->assertFileDoesNotExist(
            $this->baseDir . '/storage/cache/routes.php',
            'Booting must not create a route cache that was never requested.'
        );
    }

    /**
     * The same must hold with debug on. If the default ever goes back to being
     * coupled to debug, this is the assertion that notices.
     */
    public function testRouteCacheStaysOffWhenDebugIsEnabled(): void
    {
        $this->isolateApplicationDatabase(new Application($this->baseDir, debug: true));

        $this->assertFileDoesNotExist(
            $this->baseDir . '/storage/cache/routes.php',
            'Enabling debug must not enable route caching.'
        );
    }

    /**
     * Guards the FALLBACK, not the configured value.
     *
     * config/app.php now declares app.routeCache, which shadows the second
     * argument to Config::get() in Application::cacheRoutes(). That means a
     * regression in the fallback is invisible to the two tests above: the
     * configured value always wins, so the fallback is never reached.
     *
     * This boots an app with NO config directory at all, which is the one
     * situation where the fallback is the only thing in play. If the key is ever
     * removed from config/app.php — by a refactor, a rename, a partial revert —
     * this is what notices, and the failure is "an app started writing a cache
     * nobody asked for" rather than a fatal discovered on a deploy.
     */
    public function testRouteCacheFallbackIsOffEvenWithoutAnyConfig(): void
    {
        $bare = sys_get_temp_dir() . '/phue_bare_' . uniqid();
        mkdir($bare . '/storage', 0755, true);

        try {
            new Application($bare, debug: false);

            $this->assertFileDoesNotExist(
                $bare . '/storage/cache/routes.php',
                "Application::cacheRoutes() must default to false when app.routeCache is absent. "
                . 'A fallback of !debug silently turns cache writing on for any app whose config '
                . 'omits the key — and that cache is fatal on the next boot for any app with a '
                . 'closure route.'
            );
        } finally {
            $this->removeTree($bare);
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
