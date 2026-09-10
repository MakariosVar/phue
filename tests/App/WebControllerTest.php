<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end contract tests for WebController routes.
 *
 * WebController renders Phue .phue components via ComponentRenderer
 * at /, /docs, and /ui. Each page is served as a self-contained
 * HTML shell with window.__INITIAL_STATE__ and extracted ESM modules.
 *
 * @package Phue\Tests\App
 */
final class WebControllerTest extends TestCase
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

    // ── Root route (WebController::index → Home.phue) ──

    public function testRootRouteReturnsHtmlShell(): void
    {
        $response = $this->app->handle(new Request('GET', '/'));
        $content = $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeader('content-type'));
        $this->assertStringContainsString('<div id="app">', $content);
        $this->assertStringContainsString('window.__INITIAL_STATE__', $content);
        $this->assertStringContainsString('<script type="module"', $content);
    }

    public function testRootRouteEmbedsHomeModuleScriptUrl(): void
    {
        $content = $this->app->handle(new Request('GET', '/'))->getContent();

        $this->assertMatchesRegularExpression(
            '/<script type="module" src="\/assets\/phue\/Pages\/Home\.[a-f0-9]{8}\.phue\.js"><\/script>/',
            $content
        );
        $this->assertStringNotContainsString('<script type="module">', $content);
        $this->assertStringNotContainsString('src="/assets/app.js"', $content);
    }

    public function testRootRouteEmbedsHomeState(): void
    {
        $content = $this->app->handle(new Request('GET', '/'))->getContent();

        $this->assertStringContainsString('window.__INITIAL_STATE__', $content);

        preg_match('/window\.__INITIAL_STATE__ = (\{.*?\});/s', $content, $state);
        $this->assertArrayHasKey(1, $state);

        $decoded = json_decode($state[1], true);
        $this->assertIsArray($decoded);
        $this->assertSame('HomePage', $decoded['value']);
    }

    public function testRootRouteServesHomeModuleScript(): void
    {
        $page = $this->app->handle(new Request('GET', '/'))->getContent();
        preg_match('/src="(\/assets\/phue\/Pages\/Home\.[a-f0-9]{8}\.phue\.js)"/', $page, $module);
        $this->assertArrayHasKey(1, $module);

        $response = $this->app->handle(new Request('GET', $module[1]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/javascript; charset=utf-8', $response->getHeader('content-type'));
        $this->assertStringContainsString('createApp', $response->getContent());
        $this->assertStringContainsString(".mount('#app')", $response->getContent());
    }

    public function testRootRouteTitleContainsPhue(): void
    {
        $response = $this->app->handle(new Request('GET', '/'));
        $content = $response->getContent();

        preg_match('/<title>(.*?)<\/title>/', $content, $title);
        $this->assertNotNull($title[1] ?? null);
        $this->assertStringContainsString('Phue', $title[1]);
    }

    // ── Docs route (WebController::docs → Docs.phue) ──

    public function testDocsRouteReturnsHtml(): void
    {
        $response = $this->app->handle(new Request('GET', '/docs'));
        $content = $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeader('content-type'));
        $this->assertStringContainsString('<div id="app">', $content);
        $this->assertStringContainsString('window.__INITIAL_STATE__', $content);
    }

    public function testDocsRouteTitleContainsDocs(): void
    {
        $response = $this->app->handle(new Request('GET', '/docs'));
        $content = $response->getContent();

        preg_match('/<title>(.*?)<\/title>/', $content, $title);
        $this->assertNotNull($title[1] ?? null);
        $this->assertStringContainsString('Docs', $title[1]);
    }

    public function testDocsRouteEmbedsDocsState(): void
    {
        $content = $this->app->handle(new Request('GET', '/docs'))->getContent();

        preg_match('/window\.__INITIAL_STATE__ = (\{.*?\});/s', $content, $state);
        $this->assertArrayHasKey(1, $state);

        $decoded = json_decode($state[1], true);
        $this->assertIsArray($decoded);
        $this->assertSame('DocsPage', $decoded['value']);
    }

    // ── UI route (WebController::ui → UI.phue) ──

    public function testUiRouteReturnsHtml(): void
    {
        $response = $this->app->handle(new Request('GET', '/ui'));
        $content = $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeader('content-type'));
        $this->assertStringContainsString('<div id="app">', $content);
        $this->assertStringContainsString('window.__INITIAL_STATE__', $content);
    }

    public function testUiRouteTitleContainsUI(): void
    {
        $response = $this->app->handle(new Request('GET', '/ui'));
        $content = $response->getContent();

        preg_match('/<title>(.*?)<\/title>/', $content, $title);
        $this->assertNotNull($title[1] ?? null);
        $this->assertStringContainsString('UI', $title[1]);
    }

    public function testUiRouteEmbedsInitialState(): void
    {
        $content = $this->app->handle(new Request('GET', '/ui'))->getContent();

        preg_match('/window\.__INITIAL_STATE__ = (\{.*?\});/s', $content, $state);
        $this->assertArrayHasKey(1, $state);

        $decoded = json_decode($state[1], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('radioValue', $decoded);
        $this->assertSame('option1', $decoded['radioValue']);
        $this->assertArrayHasKey('checked', $decoded);
        $this->assertSame(true, $decoded['checked']);
    }

    public function testUiRouteTitleContainsPhui(): void
    {
        $response = $this->app->handle(new Request('GET', '/ui'));
        $content = $response->getContent();

        preg_match('/<title>(.*?)<\/title>/', $content, $title);
        $this->assertNotNull($title[1] ?? null);
        $this->assertStringContainsString('PhUI', $title[1]);
    }

    public function testUiRouteIsDocsShowcaseWithSections(): void
    {
        $content = $this->app->handle(new Request('GET', '/ui'))->getContent();

        $this->assertStringContainsString('phue-nav-link', $content);
        $this->assertStringContainsString('id="tokens"', $content);
        $this->assertStringContainsString('id="buttons"', $content);
        $this->assertStringContainsString('id="forms"', $content);
        $this->assertStringContainsString('id="data"', $content);
        $this->assertStringContainsString('id="feedback"', $content);
        $this->assertStringContainsString('id="navigation"', $content);
        $this->assertStringContainsString('btn btn-primary', $content);
        $this->assertStringContainsString('--bs-primary', $content);
    }
}
