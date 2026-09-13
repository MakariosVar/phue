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

    public function testInstallMirrorsEsmMainAndWritesManifestAndLists(): void
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

        $this->assertIsString($entry['main'], 'main URL must be a string, never an array.');
        $this->assertStringStartsWith('/assets/vendor/lodash-es/', $entry['main']);
        $this->assertIsArray($entry['subpaths']);
        $this->assertIsArray($entry['css_files']);
        $this->assertIsString($entry['installed_at']);

        $mainOnDisk = $this->dir . str_replace('/assets/vendor', '/storage/assets/vendor', $entry['main']);
        $this->assertFileExists($mainOnDisk, 'main URL must point to a real vendor file.');

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

        $manifest = json_decode((string) file_get_contents($this->dir . '/phue-packages.json'), true);
        $main = $manifest['packages']['lodash-es']['main'];
        $this->assertFileExists($this->dir . str_replace('/assets/vendor', '/storage/assets/vendor', $main));
    }

    public function testInstallMirrorsNestedEsmRootAtVendorRoot(): void
    {
        $manager = $this->manager();
        $manager->install('dayjs');

        $vendorPkgDir = $this->dir . '/storage/assets/vendor/dayjs';

        $this->assertFileExists($vendorPkgDir . '/index.js', 'ESM tree root must become the vendor package root.');
        $this->assertDirectoryDoesNotExist($vendorPkgDir . '/esm', 'Nested esm/ dir must NOT be mirrored when esm root is detected.');
        $this->assertFileDoesNotExist($vendorPkgDir . '/esm/index.js');
    }

    public function testRewriteRemovesExtensionlessRelativeImports(): void
    {
        $manager = $this->manager();
        $manager->install('dayjs');

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir . '/storage/assets/vendor/dayjs', \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'js') {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());
            preg_match_all('/(?:from\s+|import\s*\(\s*)[\'"](\.{1,2}\/[^\'"]+)[\'"]/', $content, $matches);
            foreach ($matches[1] as $spec) {
                if (!preg_match('/\.(js|mjs|cjs|json|css)$/i', $spec)) {
                    $violations[] = $file->getPathname() . ': ' . $spec;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Every relative import in the mirrored tree must carry an explicit extension.'
        );
    }

    public function testSubpathKeysAreClientRelativeAndUrlsAreNotDoubleVendored(): void
    {
        $manager = $this->manager();
        $manager->install('dayjs');

        $manifest = json_decode((string) file_get_contents($this->dir . '/phue-packages.json'), true);
        $subpaths = $manifest['packages']['dayjs']['subpaths'];

        $this->assertIsArray($subpaths);
        $this->assertNotSame([], $subpaths);

        foreach ($subpaths as $key => $url) {
            $this->assertFalse(str_starts_with((string) $key, 'esm/'), 'Subpath keys must be client-relative, trimmed of the esm root.');
            $this->assertStringNotContainsString('vendor/vendor', (string) $url, 'Subpath URLs must not contain a doubled vendor segment.');
        }

        $this->assertArrayHasKey('plugin/relativeTime', $subpaths);
        $this->assertStringEndsWith(
            '/plugin/relativeTime/index.js',
            $subpaths['plugin/relativeTime'],
            'Directory-style subpaths must map to their /index.js file.'
        );
        $this->assertArrayHasKey('locale/el', $subpaths);
        $this->assertStringEndsWith('/locale/el.js', $subpaths['locale/el'], 'Flat subpaths must map to the concrete .js file.');
    }
}