<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Asset;

use PHPUnit\Framework\TestCase;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\AssetServer;

/**
 * Hardening contracts (A1-A4):
 * A1 nosniff on every asset response,
 * A2 strict max-size cap -> 413,
 * A3 identity/symlink validation under open fd,
 * A4 non-HTML 404s mirroring the requested extension mime.
 *
 * @package Phue\Tests\Core\Asset
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class AssetServerHardeningTest extends TestCase
{
    private string $sourceDir;
    private AssetRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDir = sys_get_temp_dir() . '/phue_assets_hardening_' . uniqid();
        mkdir($this->sourceDir, 0777, true);

        $this->registry = new AssetRegistry();
        $this->registry->registerSource('/assets', $this->sourceDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->sourceDir}/*") ?: []);
        rmdir($this->sourceDir);
    }

    public function testA1NosniffHeaderPresentOn200And404(): void
    {
        file_put_contents($this->sourceDir . '/app.js', 'console.log("ok");');
        $server = new AssetServer($this->registry);

        $res200 = $server->serve('/assets/app.js');
        $this->assertNotNull($res200);
        $this->assertSame('nosniff', $res200->getHeaders()['X-Content-Type-Options']);

        $res404 = $server->serve('/assets/missing.js');
        $this->assertNotNull($res404);
        $this->assertSame('nosniff', $res404->getHeaders()['X-Content-Type-Options']);
    }

    public function testA2FileSizeLimitTriggers413(): void
    {
        file_put_contents($this->sourceDir . '/large.js', str_repeat('A', 1024 * 10));
        $server = new AssetServer($this->registry, isDebug: false, maxSizeBytes: 1024);

        $res = $server->serve('/assets/large.js');

        $this->assertSame(413, $res->getStatusCode());
        $this->assertSame('text/plain; charset=utf-8', $res->getHeader('Content-Type'));
        $this->assertSame('nosniff', $res->getHeader('X-Content-Type-Options'));
    }

    public function testA2FileAtExactLimitIsServed(): void
    {
        file_put_contents($this->sourceDir . '/edge.js', str_repeat('A', 1024));
        $server = new AssetServer($this->registry, maxSizeBytes: 1024);

        $res = $server->serve('/assets/edge.js');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1024, (int) $res->getHeader('Content-Length'));
    }

    public function testA3SymlinkEscapingSourceRootReturns404(): void
    {
        $externalTarget = sys_get_temp_dir() . '/phue_outside_target_' . uniqid();
        file_put_contents($externalTarget, 'secret');
        symlink($externalTarget, $this->sourceDir . '/escape.txt');
        $server = new AssetServer($this->registry);

        $res = $server->serve('/assets/escape.txt');

        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringNotContainsString('secret', $res->getContent());
        unlink($externalTarget);
    }

    public function testA3SymlinkInsideSourceRootIsServed(): void
    {
        file_put_contents($this->sourceDir . '/real.txt', 'inside content');
        symlink($this->sourceDir . '/real.txt', $this->sourceDir . '/alias.txt');
        $server = new AssetServer($this->registry);

        $res = $server->serve('/assets/alias.txt');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('inside content', $res->getContent());
    }

    public function testA4NotFoundPreservesRequestedMIME(): void
    {
        $server = new AssetServer($this->registry);

        $this->assertSame(
            'text/javascript; charset=utf-8',
            $server->serve('/assets/missing.js')->getHeaders()['Content-Type']
        );
        $this->assertSame(
            'text/css; charset=utf-8',
            $server->serve('/assets/missing.css')->getHeaders()['Content-Type']
        );
        $this->assertSame(
            'application/json',
            $server->serve('/assets/missing.json')->getHeaders()['Content-Type']
        );
        $this->assertSame(
            'text/plain; charset=utf-8',
            $server->serve('/assets/unknown')->getHeaders()['Content-Type']
        );
    }

    public function testA4ErrorResponsesAreNeverCached(): void
    {
        $missingDev = (new AssetServer($this->registry, true))->serve('/assets/missing.js');
        $this->assertSame('no-cache, no-store, must-revalidate', $missingDev->getHeader('Cache-Control'));

        $missingProd = (new AssetServer($this->registry, false))->serve('/assets/missing.js');
        $this->assertSame('no-cache, no-store, must-revalidate', $missingProd->getHeader('Cache-Control'));
    }

    public function testDynamicResolverResponseCarriesNosniff(): void
    {
        $this->registry->addResolver('/assets/dyn', fn (string $path): ?string => $path === 'mod.js' ? 'export {}' : null);
        $server = new AssetServer($this->registry);

        $res = $server->serve('/assets/dyn/mod.js');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('text/javascript; charset=utf-8', $res->getHeader('Content-Type'));
        $this->assertSame('nosniff', $res->getHeader('X-Content-Type-Options'));
        $this->assertSame('export {}', $res->getContent());
    }
}