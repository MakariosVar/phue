<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Asset;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phue\Core\Asset\AssetRegistry;

/**
 * Unit tests for the AssetRegistry: source registration, prefix matching and the
 * full path-traversal / null-byte / symlink security surface.
 *
 * @package Phue\Tests\Core\Asset
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class AssetRegistryTest extends TestCase
{
    private string $rootDir;
    private string $sourceDir;
    private string $siblingDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootDir = sys_get_temp_dir() . '/phue_asset_' . uniqid();
        $this->sourceDir = $this->rootDir . '/assets';
        $this->siblingDir = $this->rootDir . '/assets-secret';

        mkdir($this->sourceDir . '/sub', 0755, true);
        mkdir($this->siblingDir, 0755, true);

        file_put_contents($this->sourceDir . '/app.js', 'console.log("app");');
        file_put_contents($this->sourceDir . '/theme.css', 'body{margin:0}');
        file_put_contents($this->sourceDir . '/data.json', '{"ok":true}');
        file_put_contents($this->sourceDir . '/sub/nested.txt', 'nested');
        file_put_contents($this->sourceDir . '/../secret.txt', 'TOP_SECRET');
        file_put_contents($this->siblingDir . '/flag.txt', 'NOT_THIS');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeTree($this->rootDir);
    }

    public function testRegistersSourceWithNormalizedPrefix(): void
    {
        $registry = new AssetRegistry();
        $registry->registerSource('assets', $this->sourceDir);

        $sources = $registry->getSources();
        $this->assertArrayHasKey('/assets', $sources);
        $this->assertSame(realpath($this->sourceDir), $sources['/assets']);
    }

    public function testThrowsForMissingSourceDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AssetRegistry())->registerSource('/assets', $this->rootDir . '/does-not-exist');
    }

    public function testResolvePathReturnsRealFileInsideSource(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertSame(
            realpath($this->sourceDir . '/app.js'),
            $registry->resolvePath('/assets/app.js')
        );
    }

    public function testResolvePathFindsNestedFile(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertSame(
            realpath($this->sourceDir . '/sub/nested.txt'),
            $registry->resolvePath('/assets/sub/nested.txt')
        );
    }

    public function testResolvePathReturnsNullForUnregisteredPrefix(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertNull($registry->resolvePath('/public/app.js'));
        $this->assertNull($registry->resolvePath('/v1/assets/app.js'));
    }

    public function testResolvePathReturnsNullForMissingFile(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertNull($registry->resolvePath('/assets/missing.js'));
    }

    public function testResolvePathReturnsNullForDirectoryRequest(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertNull($registry->resolvePath('/assets'));
        $this->assertNull($registry->resolvePath('/assets/sub'));
    }

    public function testResolvePathStripsQueryAndFragment(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertSame(
            realpath($this->sourceDir . '/app.js'),
            $registry->resolvePath('/assets/app.js?v=1.2.3#top')
        );
    }

    public function testResolvePathBlocksParentDirectoryTraversal(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertNull($registry->resolvePath('/assets/../secret.txt'));
        $this->assertNull($registry->resolvePath('/assets/sub/../../secret.txt'));
        $this->assertNull($registry->resolvePath('/assets/..%2fsecret.txt'));
    }

    public function testResolvePathBlocksEtcPasswdEscape(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertNull($registry->resolvePath('/assets/../../etc/passwd'));
    }

    public function testResolvePathBlocksUrlEncodedDotSegments(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertNull($registry->resolvePath('/assets/%2e%2e/secret.txt'));
        $this->assertNull($registry->resolvePath('/assets/%2e%2e%2fsecret.txt'));
        $this->assertNull($registry->resolvePath('/assets/%2E%2E/secret.txt'));
        $this->assertNull($registry->resolvePath('/assets/%252e%252e%252fsecret.txt'));
    }

    public function testResolvePathBlocksBackslashEncodedTraversal(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertNull($registry->resolvePath('/assets/..%5c..%5csecret.txt'));
        $this->assertNull($registry->resolvePath('/assets/..\\..\\secret.txt'));
    }

    public function testResolvePathBlocksSiblingPrefixExploit(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        // /assets → base dir; /assets-  shares the string prefix /assets but is a sibling.
        // Must NOT resolve into assets-secret regardless of traversal spelling.
        $this->assertNull($registry->resolvePath('/assets/../assets-secret/flag.txt'));
        $this->assertNull($registry->resolvePath('/assets/../../assets-secret/flag.txt'));
    }

    public function testResolvePathBlocksSymlinkEscape(): void
    {
        @symlink($this->rootDir . '/secret.txt', $this->sourceDir . '/leak.js');

        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        if (is_link($this->sourceDir . '/leak.js')) {
            $this->assertNull($registry->resolvePath('/assets/leak.js'));
        } else {
            $this->markTestSkipped('symlink not available');
        }
    }

    public function testResolvePathHandlesNullByteGracefully(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertNull($registry->resolvePath("/assets/app.js\0"));
        $this->assertNull($registry->resolvePath("/assets/app.js\0.xyz"));
    }

    public function testResolvePathAllowsDotDotThatNormalizesInsideBase(): void
    {
        $registry = (new AssetRegistry())->registerSource('/assets', $this->sourceDir);

        $this->assertSame(
            realpath($this->sourceDir . '/app.js'),
            $registry->resolvePath('/assets/sub/../app.js')
        );
    }

    public function testLongestPrefixMatchWins(): void
    {
        $vendorDir = $this->rootDir . '/vendor';
        mkdir($vendorDir);
        file_put_contents($vendorDir . '/vue.js', 'VUE_ESM');

        $registry = (new AssetRegistry())
            ->registerSource('/assets', $this->sourceDir)
            ->registerSource('/assets/vendor', $vendorDir);

        $this->assertSame(
            realpath($vendorDir . '/vue.js'),
            $registry->resolvePath('/assets/vendor/vue.js')
        );
        $this->assertSame(
            realpath($this->sourceDir . '/app.js'),
            $registry->resolvePath('/assets/app.js')
        );
    }

    public function testAddScriptDeduplicatesByUrl(): void
    {
        $registry = (new AssetRegistry())->addScript('/assets/app.js')->addScript('/assets/app.js');

        $this->assertCount(1, $registry->getScripts());
    }

    public function testAddScriptKeepsModuleFlag(): void
    {
        $registry = (new AssetRegistry())->addScript('/assets/vendor/vue.js', true);

        $scripts = $registry->getScripts();
        $this->assertSame(
            ['url' => '/assets/vendor/vue.js', 'isModule' => true],
            $scripts[0]
        );
    }

    public function testAddScriptSameUrlKeepsFirstModuleFlag(): void
    {
        $registry = (new AssetRegistry())->addScript('/assets/app.js', true)->addScript('/assets/app.js', false);

        $scripts = $registry->getScripts();
        $this->assertCount(1, $scripts);
        $this->assertTrue($scripts[0]['isModule']);
    }

    public function testAddStyleDeduplicatesByUrl(): void
    {
        $registry = (new AssetRegistry())->addStyle('/assets/theme.css')->addStyle('/assets/theme.css');

        $this->assertCount(1, $registry->getStyles());
    }

    public function testGetScriptsMaintainsRegistrationOrder(): void
    {
        $registry = (new AssetRegistry())
            ->addScript('/assets/first.js')
            ->addScript('/assets/second.js')
            ->addScript('/assets/third.js', true);

        $urls = array_column($registry->getScripts(), 'url');
        $this->assertSame(['/assets/first.js', '/assets/second.js', '/assets/third.js'], $urls);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_link($path) || is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->removeTree($path);
            }
        }

        @rmdir($dir);
    }
}