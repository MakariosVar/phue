<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Console;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

    #[RunInSeparateProcess]
    public function testExplicitConstantWinsOverDiscoveryAndCwd(): void
    {
        define('PHUE_BASE_PATH', $this->tmpBase);

        // cwd points at a marker-less directory; the constant must win regardless.
        $markerless = $this->tmpBase . '/markerless';
        mkdir($markerless, 0777, true);
        chdir($markerless);

        $this->assertSame($this->tmpBase, PathResolver::resolveBasePath());
    }

    #[RunInSeparateProcess]
    public function testEmptyConstantIsIgnoredAndFallsBackToCwd(): void
    {
        define('PHUE_BASE_PATH', '');

        $markerless = $this->tmpBase . '/markerless';
        mkdir($markerless, 0777, true);
        chdir($markerless);

        $this->assertSame($markerless, PathResolver::resolveBasePath());
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