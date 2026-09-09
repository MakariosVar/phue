<?php

namespace Phue\Tests\Core;

use PHPUnit\Framework\TestCase;
use Phue\Core\Application;
use Phue\Core\Env;
use Phue\Core\functions;

/**
 * Unit and integration tests for the Env .env loader and env() helper.
 *
 * @package Phue\Tests\Core
 */
class EnvTest extends TestCase
{
    /**
     * Temporary base paths created for the current test.
     *
     * @var array<int, string>
     */
    private array $basePaths = [];

    /**
     * Environment keys that were written to the process and need cleanup.
     *
     * @var array<int, string>
     */
    private array $touchedKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetEnvState();
    }

    protected function tearDown(): void
    {
        $this->cleanupTouchedKeys();
        $this->resetEnvState();

        foreach ($this->basePaths as $basePath) {
            $this->removeDir($basePath);
        }
        $this->basePaths = [];

        parent::tearDown();
    }

    /**
     * Reset the internal static Env cache so each test starts clean.
     */
    private function resetEnvState(): void
    {
        $reflection = new \ReflectionClass(Env::class);
        $variables = $reflection->getProperty('variables');
        $variables->setValue(null, []);
        $loaded = $reflection->getProperty('loaded');
        $loaded->setValue(null, false);
    }

    /**
     * Remove putenv/$_ENV/$_SERVER entries the test introduced.
     */
    private function cleanupTouchedKeys(): void
    {
        foreach ($this->touchedKeys as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
        $this->touchedKeys = [];
    }

    /**
     * Track a key so the test harness can clean it up.
     */
    private function touchKey(string $key): void
    {
        $this->touchedKeys[] = $key;
    }

    /**
     * Load a .env file and track every key it writes for cleanup.
     */
    private function loadEnv(string $basePath): void
    {
        if (file_exists($basePath . '/.env')) {
            $content = file_get_contents($basePath . '/.env');
            preg_match_all('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=/m', $content, $matches);
            foreach ($matches[1] as $key) {
                $this->touchKey($key);
            }
        }

        Env::load($basePath);
    }

    /**
     * Build a temporary base path containing the given relative files.
     *
     * @param array<string, string> $files Relative path => raw content.
     */
    private function makeBasePath(array $files): string
    {
        $base = sys_get_temp_dir() . '/phue_env_test_' . uniqid();
        mkdir($base . '/config', 0777, true);

        foreach ($files as $relative => $content) {
            $path = $base . '/' . $relative;
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($path, $content);
        }

        $this->basePaths[] = $base;
        return $base;
    }

    /**
     * Recursively remove a temporary directory.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }

    /**
     * Tests that a basic KEY=VALUE pair is parsed and returned.
     */
    public function testLoadParsesBasicKeyValuePairs(): void
    {
        $base = $this->makeBasePath([".env" => "PHUE_TEST_NAME=Phue\nPHUE_TEST_DEBUG=true\n"]);

        $this->loadEnv($base);

        $this->assertSame('Phue', Env::get('PHUE_TEST_NAME'));
        $this->assertSame(true, Env::get('PHUE_TEST_DEBUG'));
    }

    /**
     * Tests that comment and empty lines are skipped.
     */
    public function testLoadSkipsCommentsAndEmptyLines(): void
    {
        $base = $this->makeBasePath(['.env' => "# comment line\n\nPHUE_TEST_KEY=value\n# trailing comment\n"]);

        $this->loadEnv($base);

        $this->assertSame('value', Env::get('PHUE_TEST_KEY'));
        $this->assertNull(Env::get('PHUE_TEST_MISSING'));
    }

    /**
     * Tests that wrapping single/double quotes are stripped.
     */
    public function testLoadStripsQuotes(): void
    {
        $base = $this->makeBasePath([".env" => "PHUE_TEST_DQ=\"double\"\nPHUE_TEST_SQ='single'\n"]);

        $this->loadEnv($base);

        $this->assertSame('double', Env::get('PHUE_TEST_DQ'));
        $this->assertSame('single', Env::get('PHUE_TEST_SQ'));
    }

    /**
     * Tests that inline comments are stripped for unquoted values only.
     */
    public function testLoadStripsInlineComments(): void
    {
        $base = $this->makeBasePath([".env" => "PHUE_TEST_NO_COMMENT=value # inline\nPHUE_TEST_QUOTED=\"keep # inline\"\n"]);

        $this->loadEnv($base);

        $this->assertSame('value', Env::get('PHUE_TEST_NO_COMMENT'));
        $this->assertSame('keep # inline', Env::get('PHUE_TEST_QUOTED'));
    }

    /**
     * Tests that surrounding whitespace is trimmed from keys and values.
     */
    public function testLoadTrimsWhitespace(): void
    {
        $base = $this->makeBasePath([".env" => "  PHUE_TEST_SPACED   =   padded value  \n"]);

        $this->loadEnv($base);

        $this->assertSame('padded value', Env::get('PHUE_TEST_SPACED'));
    }

    /**
     * Tests boolean/null casting of common literal values.
     */
    public function testLoadCastsSpecialLiterals(): void
    {
        $base = $this->makeBasePath([
            ".env" => "PHUE_TEST_T1=true\nPHUE_TEST_T2=(true)\n"
                . "PHUE_TEST_F1=false\nPHUE_TEST_F2=(false)\n"
                . "PHUE_TEST_N1=null\nPHUE_TEST_N2=(null)\nPHUE_TEST_N3=empty\n",
        ]);

        $this->loadEnv($base);

        $this->assertTrue(Env::get('PHUE_TEST_T1'));
        $this->assertTrue(Env::get('PHUE_TEST_T2'));
        $this->assertFalse(Env::get('PHUE_TEST_F1'));
        $this->assertFalse(Env::get('PHUE_TEST_F2'));
        $this->assertNull(Env::get('PHUE_TEST_N1'));
        $this->assertNull(Env::get('PHUE_TEST_N2'));
        $this->assertNull(Env::get('PHUE_TEST_N3'));
    }

    /**
     * Tests that get() returns the default when a key is absent.
     */
    public function testGetReturnsDefaultForMissingKey(): void
    {
        $this->assertSame('fallback', Env::get('PHUE_TEST_NOT_SET', 'fallback'));
        $this->assertNull(Env::get('PHUE_TEST_NOT_SET'));
    }

    /**
     * Tests that get() falls back to real process environment variables.
     */
    public function testGetFallsBackToProcessEnvironment(): void
    {
        putenv('PHUE_TEST_FALLBACK=envval');
        $this->touchKey('PHUE_TEST_FALLBACK');

        $this->assertSame('envval', Env::get('PHUE_TEST_FALLBACK'));
    }

    /**
     * Tests that process environment fallbacks are type-cast like loaded ones.
     */
    public function testGetCastsProcessEnvironmentValue(): void
    {
        putenv('PHUE_TEST_CAST=false');
        $this->touchKey('PHUE_TEST_CAST');

        $this->assertFalse(Env::get('PHUE_TEST_CAST'));
    }

    /**
     * Tests that a missing or unreadable .env file is harmless.
     */
    public function testLoadMissingFileIsHarmless(): void
    {
        $base = $this->makeBasePath([]);

        $this->loadEnv($base);

        $this->assertNull(Env::get('PHUE_TEST_NOPE'));
    }

    /**
     * Tests the single-load guard: subsequent loads are ignored.
     */
    public function testLoadGuardPreventsReload(): void
    {
        $base = $this->makeBasePath(['.env' => 'PHUE_TEST_VALUE=first']);

        $this->loadEnv($base);
        $this->assertSame('first', Env::get('PHUE_TEST_VALUE'));

        file_put_contents($base . '/.env', 'PHUE_TEST_VALUE=second');
        $this->loadEnv($base);
        $this->assertSame('first', Env::get('PHUE_TEST_VALUE'));

        $this->resetEnvState();
        $this->loadEnv($base);
        $this->assertSame('second', Env::get('PHUE_TEST_VALUE'));
    }

    /**
     * Tests that the global env() helper returns loaded values.
     */
    public function testEnvHelperReturnsLoadedValue(): void
    {
        $base = $this->makeBasePath(['.env' => 'PHUE_TEST_HELPER=works']);

        $this->loadEnv($base);

        $this->assertSame('works', \Phue\Core\env('PHUE_TEST_HELPER', 'nope'));
        $this->assertSame('nope', \Phue\Core\env('PHUE_TEST_HELPER_MISSING', 'nope'));
    }

    /**
     * Integration: a full Application boot reads values from its .env file.
     */
    public function testApplicationBootReadsDotEnvValues(): void
    {
        $base = $this->makeBasePath([
            '.env'        => "PHUE_BOOT_FLAG=loaded\n",
            'config/app.php' => "<?php\n\nuse function Phue\\Core\\env;\n\n"
                . "return [\n"
                . "    'flag' => env('PHUE_BOOT_FLAG', 'not-loaded'),\n"
                . "    'modules' => [],\n"
                . "];\n",
        ]);

        $app = new Application($base);
        $this->touchKey('PHUE_BOOT_FLAG');

        $this->assertSame('loaded', $app->getConfig()->get('app.flag'));
    }

    /**
     * Integration: application defaults are used when no .env defines the key.
     */
    public function testApplicationBootUsesDefaultsWithoutDotEnv(): void
    {
        $base = $this->makeBasePath([
            'config/app.php' => "<?php\n\nuse function Phue\\Core\\env;\n\n"
                . "return [\n"
                . "    'flag' => env('PHUE_BOOT_FLAG', 'not-loaded'),\n"
                . "    'modules' => [],\n"
                . "];\n",
        ]);

        $app = new Application($base);
        $this->touchKey('PHUE_BOOT_FLAG');

        $this->assertSame('not-loaded', $app->getConfig()->get('app.flag'));
    }
}