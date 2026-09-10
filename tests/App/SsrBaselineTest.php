<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Request;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SsrBaselineTest — freezes the current pre-SSR behavior of the SPA/HTML
 * pipeline at /.
 *
 * This is the Phase 0 behavior snapshot: the mount container is filled with the
 * RAW Vue template (client-side rendering), the initial state is embedded as
 * JSON, and the X-Phue-SPA request still receives the un-rendered template.
 * Once SSR lands, the raw-template assertions below are the exact seams that
 * must flip to server-rendered, hydrated markup.
 *
 * @package Phue\Tests\App
 */
#[Group('ssr-baseline')]
final class SsrBaselineTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application('/app');
        $this->app->getRouter()->scanControllers('/app/App/Controllers', 'App\\Controllers');
        $registry = $this->app->getContainer()->get(AssetRegistry::class);
        $registry->registerSource('/assets', '/app/App/Frontend');
        $registry->registerSource('/assets/vendor', '/app/storage/assets/vendor');
    }

    public function testRootStillServesRawTemplateNotSsrHtml(): void
    {
        $response = $this->app->handle(new Request('GET', '/'));
        $content = $response->getContent();

        $this->assertSame(200, $response->getStatusCode());

        // Mount container is filled with the UN-rendered template (client-side mount).
        $this->assertStringContainsString('{{', $content);

        // SSR marker must NOT exist yet.
        $this->assertStringNotContainsString('data-phue-ssr', $content);
        $this->assertStringNotContainsString('data-server-rendered', $content);
    }

    public function testRootStillEmbedsInitialStateJson(): void
    {
        $content = $this->app->handle(new Request('GET', '/'))->getContent();

        $this->assertMatchesRegularExpression(
            '/window\.__INITIAL_STATE__\s*=\s*(\{.*?\});/s',
            $content
        );
    }

    public function testSpaRequestStillReturnsRawTemplateInMountContainer(): void
    {
        $response = $this->app->handle(
            new Request('GET', '/', [], [], ['X-Phue-SPA' => 'true'])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $payload = json_decode($response->getContent(), true);

        $this->assertIsArray($payload);
        $this->assertStringContainsString('{{', $payload['html']);
        $this->assertArrayHasKey('state', $payload);
        $this->assertArrayHasKey('scripts', $payload);
    }
}