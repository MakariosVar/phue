<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Config;
use Phue\Core\Request;
use Phue\Core\Ssr\UnixSsrClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RestoresActiveRecordConnection;

/**
 * SsrBaselineTest — locks the SSR milestone reached at the end of Phase 3:
 * the server now renders Vue template state into the mount container instead of
 * shipping the raw `{{ }}` template (FOUC eliminated).
 *
 * Before the migration this suite froze the CSR baseline: the mount container
 * held the UN-rendered template. With the SsrEngine seam active and the Bun
 * sidecar up, the same requests must now produce rendered, hydrated markup.
 * The full-page path and the X-Phue-SPA JSON path are both covered.
 *
 * These tests require a live sidecar (the same contract as the real runtime),
 * so they self-skip when `ssr/server.ts` is not reachable on the abstract socket.
 *
 * @package Phue\Tests\App
 */
#[Group('ssr-baseline')]
#[Group('integration')]
final class SsrBaselineTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application('/app');
        $this->isolateApplicationDatabase($this->app);
        $this->app->getRouter()->scanControllers('/app/App/Controllers', 'App\\Controllers');
        $registry = $this->app->getContainer()->get(AssetRegistry::class);
        $registry->registerSource('/assets', '/app/App/Frontend');
        $registry->registerSource('/assets/vendor', '/app/storage/assets/vendor');

        if (!$this->ssrClient()->isAvailable()) {
            self::markTestSkipped('Bun SSR sidecar is not reachable.');
        }
    }

    private function ssrClient(): UnixSsrClient
    {
        $config = $this->app->getContainer()->get(Config::class);

        return new UnixSsrClient(
            socket: (string) $config->get('ssr.socket', "\0phue-ssr"),
        );
    }

    public function testRootServesRenderedHtmlWithoutFouc(): void
    {
        $response = $this->app->handle(new Request('GET', '/'));
        $content = $response->getContent();

        $this->assertSame(200, $response->getStatusCode());

        // FOUC seam flipped: the mount container must NOT leak raw `{{ }}`.
        $this->assertStringNotContainsString('{{', $content);

        // The rendered Vue output (server-side) is present instead.
        $this->assertStringContainsString('<h2>Phue App</h2>', $content);
    }

    public function testRootStillEmbedsInitialStateJson(): void
    {
        $content = $this->app->handle(new Request('GET', '/'))->getContent();

        $this->assertMatchesRegularExpression(
            '/window\.__INITIAL_STATE__\s*=\s*(\{.*?\});/s',
            $content
        );
    }

    public function testSpaRequestReturnsRenderedHtmlInMountContainer(): void
    {
        $response = $this->app->handle(
            new Request('GET', '/', [], [], ['X-Phue-SPA' => 'true'])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $payload = json_decode($response->getContent(), true);

        $this->assertIsArray($payload);
        $this->assertStringNotContainsString('{{', $payload['html']);
        $this->assertStringContainsString('<h2>Phue App</h2>', $payload['html']);
        $this->assertArrayHasKey('state', $payload);
        $this->assertArrayHasKey('scripts', $payload);
    }
}