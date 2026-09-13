<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Asset;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Asset\NpmPackageManager;
use RuntimeException;

/**
 * Integration tests for NpmPackageManager against the real Bun runtime.
 *
 * Runs inside the Docker container (bun is only ever invoked from the
 * container, per the "everything runs in Docker" decision), but always
 * operates on a throwaway temp dir — never on the project tree.
 *
 * @package Phue\Tests\Core\Asset
 */
#[Group('integration')]
final class NpmPackageManagerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        if (trim((string) shell_exec('command -v bun')) === '') {
            $this->markTestSkipped('bun is unavailable; cannot run NpmPackageManager integration.');
        }

        $this->dir = sys_get_temp_dir() . '/npmmgr_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->dir);
        parent::tearDown();
    }

    private function manager(): NpmPackageManager
    {
        return new NpmPackageManager($this->dir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->recursiveDelete($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    public function testInstallBuildsBundleWritesManifestAndLists(): void
    {
        $manager = $this->manager();
        $manager->install('lodash-es', 'latest');

        $manifest = json_decode(
            (string) file_get_contents($this->dir . '/phue-packages.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertArrayHasKey('lodash-es', $manifest['packages']);

        $entry = $manifest['packages']['lodash-es'];
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $entry['version']);
        $this->assertSame('latest', $entry['resolved_from']);
        $this->assertSame('lodash-es.esm.js', $entry['esm_file']);
        $this->assertStringStartsWith('sha256-', $entry['esm_integrity']);
        $this->assertIsArray($entry['css_files']);
        $this->assertIsString($entry['installed_at']);

        $bundle = $this->dir . '/storage/assets/vendor/lodash-es/lodash-es.esm.js';
        $this->assertFileExists($bundle);

        $actual = 'sha256-' . base64_encode(hash_file('sha256', $bundle, true));
        $this->assertSame($entry['esm_integrity'], $actual, 'Recorded integrity must match the built file.');

        $this->assertSame(['lodash-es'], array_keys($manager->listPackages()));
    }

    public function testInvalidPackageNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->manager()->install('UPPER Case!', 'latest');
    }

    public function testRemoveCleansVendorDirAndManifestEntry(): void
    {
        $manager = $this->manager();
        $manager->install('lodash-es');

        $this->assertTrue($manager->remove('lodash-es'));
        $this->assertSame([], $manager->listPackages());
        $this->assertDirectoryDoesNotExist($this->dir . '/storage/assets/vendor/lodash-es');
    }

    public function testRemoveOnMissingPackageReturnsFalse(): void
    {
        $this->assertFalse($this->manager()->remove('chart.js'));
    }

    public function testRestoreRebuildsVendorArtifactsFromManifest(): void
    {
        $manager = $this->manager();
        $manager->install('lodash-es');

        $this->recursiveDelete($this->dir . '/storage/assets/vendor');
        $this->assertDirectoryDoesNotExist($this->dir . '/storage/assets/vendor');

        $manager->restore();

        $this->assertDirectoryExists($this->dir . '/storage/assets/vendor/lodash-es');
        $this->assertFileExists($this->dir . '/storage/assets/vendor/lodash-es/lodash-es.esm.js');
    }
}