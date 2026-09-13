<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Console;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Console\PathResolver;

/**
 * Unit contract tests for the CLI PathResolver discovery hierarchy.
 *
 * Resolution priority under test:
 *   1. Explicit PHUE_BASE_PATH constant (non-empty string) wins unconditionally.
 *   2. Upward marker discovery (composer.json, config/, App/) from cwd.
 *   3. getcwd() fallback when nothing is found above.
 *
 * @package Phue\Tests\Core\Console
 */
#[Group('unit')]
final class PathResolverTest extends TestCase
{
    private string $tmpBase;
    private string $oldCwd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oldCwd = (string) getcwd();
        $this->tmpBase = sys_get_temp_dir() . '/phue_path_resolver_' . uniqid();
        mkdir($this->tmpBase, 0777, true);
    }

    protected function tearDown(): void
    {
        chdir($this->oldCwd);
        $this->rmTree($this->tmpBase);
        parent::tearDown();
    }

    /**
     * Runs PathResolver in a freshly spawned PHP process so the constant can
     * be defined without polluting this process (no #[RunInSeparateProcess]:
     * PHPUnit's isolation is brittle once the full suite is loaded).
     */
    private function resolveInSubprocess(string $constantValue, ?string $cwd = null): string
    {
        $script = sprintf(
            "define('PHUE_BASE_PATH', %s);" .
            "require %s;" .
            "echo \\Phue\\Core\\Console\\PathResolver::resolveBasePath();",
            "'" . addslashes($constantValue) . "'",
            var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true),
        );

        $command = [PHP_BINARY, '-r', $script];
        $proc    = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
        );

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        $this->assertSame(0, $exitCode, 'Subprocess exited non-zero: ' . $stderr);

        return $stdout;
    }

    public function testExplicitConstantWinsOverDiscoveryAndCwd(): void
    {
        $markerless = $this->tmpBase . '/markerless';
        mkdir($markerless, 0777, true);

        $this->assertSame(
            $this->tmpBase,
            $this->resolveInSubprocess($this->tmpBase, $markerless),
        );
    }

    public function testEmptyConstantIsIgnoredAndFallsBackToCwd(): void
    {
        $markerless = $this->tmpBase . '/markerless';
        mkdir($markerless, 0777, true);

        $this->assertSame(
            $markerless,
            $this->resolveInSubprocess('', $markerless),
        );
    }

    public function testDiscoveryFindsComposerJsonMarkerUpward(): void
    {
        file_put_contents($this->tmpBase . '/composer.json', '{}');

        mkdir($this->tmpBase . '/a1/b1/c1', 0777, true);
        chdir($this->tmpBase . '/a1/b1/c1');

        $this->assertSame($this->tmpBase, PathResolver::resolveBasePath());
    }

    public function testDiscoveryFindsConfigDirMarkerUpward(): void
    {
        mkdir($this->tmpBase . '/config', 0777, true);

        mkdir($this->tmpBase . '/a2/b2/c2', 0777, true);
        chdir($this->tmpBase . '/a2/b2/c2');

        $this->assertSame($this->tmpBase, PathResolver::resolveBasePath());
    }

    public function testDiscoveryFindsAppDirMarkerUpward(): void
    {
        mkdir($this->tmpBase . '/App', 0777, true);

        mkdir($this->tmpBase . '/a3/b3/c3', 0777, true);
        chdir($this->tmpBase . '/a3/b3/c3');

        $this->assertSame($this->tmpBase, PathResolver::resolveBasePath());
    }

    public function testNearestMarkerWinsDuringUpwardTraversal(): void
    {
        file_put_contents($this->tmpBase . '/composer.json', '{}');

        $innerApp = $this->tmpBase . '/inner';
        mkdir($innerApp . '/nested', 0777, true);
        file_put_contents($innerApp . '/composer.json', '{}');

        chdir($innerApp . '/nested');

        // The nearest composer.json (inner) shadows the outer one.
        $this->assertSame($innerApp, PathResolver::resolveBasePath());
    }

    public function testFallsBackToCwdWhenNoMarkersExist(): void
    {
        $markerless = $this->tmpBase . '/markerless';
        mkdir($markerless . '/sub', 0777, true);
        chdir($markerless . '/sub');

        $this->assertSame($markerless . '/sub', PathResolver::resolveBasePath());
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*', GLOB_NOSORT) ?: [] as $entry) {
            is_dir($entry) ? $this->rmTree($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }
}