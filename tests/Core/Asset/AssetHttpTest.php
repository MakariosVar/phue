<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Asset;

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Request;
use Phue\Core\Response;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RestoresActiveRecordConnection;

/**
 * HTTP contract tests for the auto-registered AssetsController: end-to-end
 * requests through Application::handle() against a real temporary file tree,
 * covering serving, 404 semantics, header strategy and the traversal surface
 * as exposed over HTTP.
 *
 * @package Phue\Tests\Core\Asset
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class AssetHttpTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private string $baseDir;
    private string $sourceDir;
    private string $siblingDir;
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = sys_get_temp_dir() . '/phue_asset_http_' . uniqid();
        $this->sourceDir = $this->baseDir . '/assets';
        $this->siblingDir = $this->baseDir . '/assets-secret';

        mkdir($this->sourceDir . '/js', 0755, true);
        mkdir($this->siblingDir, 0755, true);

        file_put_contents($this->sourceDir . '/app.js', 'const greeting = "hello";');
        file_put_contents($this->sourceDir . '/theme.css', 'body { color: #333; }');
        file_put_contents($this->sourceDir . '/js/app.js', 'console.log("nested");');
        file_put_contents($this->sourceDir . '/data.json', '{"ok":true}');
        file_put_contents($this->baseDir . '/secret.txt', 'TOP_SECRET');
        file_put_contents($this->siblingDir . '/flag.txt', 'NOT_THIS');

        $this->app = new Application($this->baseDir);

        $this->app->getContainer()->get(AssetRegistry::class)
            ->registerSource('/assets', $this->sourceDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeTree($this->baseDir);
    }

    public function testServesJavaScriptAsset(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/app.js'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('const greeting = "hello";', $response->getContent());
        $this->assertSame('text/javascript; charset=utf-8', $response->getHeader('content-type'));
    }

    public function testServesCssAsset(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/theme.css'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('body { color: #333; }', $response->getContent());
        $this->assertSame('text/css; charset=utf-8', $response->getHeader('content-type'));
    }

    public function testServesJsonAsset(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/data.json'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeader('content-type'));
    }

    public function testServesNestedMultiSegmentPath(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/js/app.js'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('console.log("nested");', $response->getContent());
    }

    public function testContentLengthMatchesFileBytes(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/app.js'));

        $this->assertSame(
            (string) strlen('const greeting = "hello";'),
            $response->getHeader('content-length')
        );
    }

    public function testMissingFileReturns404(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/does-not-exist.js'));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testMissingDirectoryReturns404(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/unknown/deep/file.css'));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUnknownPrefixReturns404(): void
    {
        $response = $this->app->handle(new Request('GET', '/vendor/jquery.js'));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRootAssetsPathReturns404(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets'));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testLiteralTraversalOverHttpReturns404(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/../secret.txt'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('TOP_SECRET', $response->getContent());
    }

    public function testEncodedTraversalOverHttpReturns404(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/%2e%2e/secret.txt'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('TOP_SECRET', $response->getContent());
    }

    public function testDoubleEncodedTraversalOverHttpReturns404(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/%252e%252e/secret.txt'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('TOP_SECRET', $response->getContent());
    }

    public function testDeepTraversalAttemptIsSquashedTo404(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/js/../../secret.txt'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('TOP_SECRET', $response->getContent());
    }

    public function testSiblingPrefixExploitOverHttpReturns404(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/../assets-secret/flag.txt'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('NOT_THIS', $response->getContent());
    }

    public function testNullByteInPathIsRejectedWithoutCrash(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/app.js%00.txt'));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testProductionCacheControlIsImmutable(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/app.js'));

        $this->assertSame(
            'public, max-age=31536000, immutable',
            $response->getHeader('cache-control')
        );
    }

    public function testDebugCacheControlDisablesCaching(): void
    {
        $debugBaseDir = sys_get_temp_dir() . '/phue_asset_http_debug_' . uniqid();
        mkdir($debugBaseDir . '/config', 0755, true);
        file_put_contents(
            $debugBaseDir . '/config/app.php',
            '<?php return ["debug" => true];'
        );
        mkdir($debugBaseDir . '/assets', 0755, true);
        file_put_contents($debugBaseDir . '/assets/app.js', 'const x = 1;');

        try {
            $debugApp = new Application($debugBaseDir);
            $debugApp->getContainer()->get(AssetRegistry::class)
                ->registerSource('/assets', $debugBaseDir . '/assets');

            $response = $debugApp->handle(new Request('GET', '/assets/app.js'));

            $this->assertSame(
                'no-cache, no-store, must-revalidate',
                $response->getHeader('cache-control')
            );
        } finally {
            $this->removeTree($debugBaseDir);
        }
    }

    /**
     * Recursively remove a directory tree.
     */
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
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}