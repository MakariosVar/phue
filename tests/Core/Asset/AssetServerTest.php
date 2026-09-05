<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Asset;

use PHPUnit\Framework\TestCase;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\AssetServer;

/**
 * Unit tests for the AssetServer: content types, cache strategies and the
 * null (delegate 404) contract on unresolved URIs.
 *
 * @package Phue\Tests\Core\Asset
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class AssetServerTest extends TestCase
{
    private string $sourceDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDir = sys_get_temp_dir() . '/phue_asset_server_' . uniqid();
        mkdir($this->sourceDir, 0755, true);

        file_put_contents($this->sourceDir . '/app.js', 'console.log("app");');
        file_put_contents($this->sourceDir . '/theme.css', 'body{margin:0}');
        file_put_contents($this->sourceDir . '/data.json', '{"ok":true}');
        file_put_contents($this->sourceDir . '/NOTES', 'no extension');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeTree($this->sourceDir);
    }

    private function registry(): AssetRegistry
    {
        return (new AssetRegistry())->registerSource('/assets', $this->sourceDir);
    }

    public function testServesRegisteredJavaScriptAsset(): void
    {
        $response = (new AssetServer($this->registry()))->serve('/assets/app.js');

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('console.log("app");', $response->getContent());
        $this->assertSame('text/javascript; charset=utf-8', $response->getHeader('Content-Type'));
        $this->assertSame((string) strlen('console.log("app");'), $response->getHeader('Content-Length'));
    }

    public function testServesCssAsset(): void
    {
        $response = (new AssetServer($this->registry()))->serve('/assets/theme.css');

        $this->assertNotNull($response);
        $this->assertSame('text/css; charset=utf-8', $response->getHeader('Content-Type'));
    }

    public function testServesJsonAsset(): void
    {
        $response = (new AssetServer($this->registry()))->serve('/assets/data.json');

        $this->assertNotNull($response);
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
    }

    public function testUnknownExtensionFallsBackToTextPlain(): void
    {
        $response = (new AssetServer($this->registry()))->serve('/assets/NOTES');

        $this->assertNotNull($response);
        $this->assertSame('text/plain; charset=utf-8', $response->getHeader('Content-Type'));
    }

    public function testUnmatchedSourcePrefixReturnsNull(): void
    {
        $server = new AssetServer($this->registry());

        $this->assertNull($server->serve('/other/app.js'));
    }

    public function testMissingFileUnderKnownPrefixReturnsNonHtml404(): void
    {
        $response = (new AssetServer($this->registry()))->serve('/assets/missing.js');

        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('text/javascript; charset=utf-8', $response->getHeader('Content-Type'));
        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }

    public function testDebugModeDisablesCaching(): void
    {
        $response = (new AssetServer($this->registry(), true))->serve('/assets/app.js');

        $this->assertNotNull($response);
        $this->assertSame('no-cache, no-store, must-revalidate', $response->getHeader('Cache-Control'));
    }

    public function testProductionModeSetsImmutableCache(): void
    {
        $response = (new AssetServer($this->registry()))->serve('/assets/app.js');

        $this->assertNotNull($response);
        $this->assertSame('public, max-age=31536000, immutable', $response->getHeader('Cache-Control'));
    }

    public function testProductionIsDefaultMode(): void
    {
        $server = new AssetServer($this->registry());
        $response = $server->serve('/assets/app.js');

        $this->assertNotNull($response);
        $this->assertSame('public, max-age=31536000, immutable', $response->getHeader('Cache-Control'));
    }

    public function testServeStripsQueryString(): void
    {
        $response = (new AssetServer($this->registry()))->serve('/assets/app.js?v=2');

        $this->assertNotNull($response);
        $this->assertSame('console.log("app");', $response->getContent());
    }

    private function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->removeTree($path);
            }
        }

        @rmdir($dir);
    }
}