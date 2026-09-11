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
        $this->assertSame('HomePage', $decoded['testValue'] ?? null);
    }

    public function testRootRouteServesHomeModuleScript(): void
    {
        $page = $this->app->handle(new Request('GET', '/'))->getContent();
        preg_match('/src="(\/assets\/phue\/Pages\/Home\.[a-f0-9]{8}\.phue\.js)"/', $page, $module);
        $this->assertArrayHasKey(1, $module);

        $response = $this->app->handle(new Request('GET', $module[1]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/javascript; charset=utf-8', $response->getHeader('content-type'));
        $this->assertStringContainsString('createSSRApp', $response->getContent());
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
        $this->assertSame('DocsPage', $decoded['testValue'] ?? null);
    }

    // ── Docs route (WebController::docs → Docs.phue) ──

    public function testDocsRouteMarksDocsNavLinkActive(): void
    {
        $content = $this->app->handle(new Request('GET', '/docs'))->getContent();

        $this->assertStringContainsString(
            '<a href="/docs" class="nav-link active" aria-current="page">Docs</a>',
            $content
        );
        $this->assertMatchesRegularExpression('/<a href="\/" class="nav-link">Home<\/a>/', $content);
    }

    public function testDocsRouteNavUsesHorizontalDefault(): void
    {
        $content = $this->app->handle(new Request('GET', '/docs'))->getContent();

        $this->assertStringContainsString('<nav class="nav nav-pills">', $content);
        $this->assertStringNotContainsString('<nav class="nav nav-pills flex-column">', $content);
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

    public function testUiRouteIsComponentShowcase(): void
    {
        $content = $this->app->handle(new Request('GET', '/ui'))->getContent();

        // Sidebar + usage code blocks still ship in the served HTML.
        $this->assertStringContainsString('phue-nav-link', $content);
        $this->assertStringContainsString('loadPhueWhitelist', $content);
        $this->assertStringContainsString('phue-code-block', $content);

        // SSR reality: the raw live component tags are no longer in the DOM —
        // Phui modules are client-only (Phase 5), so the server leaves the
        // hydration seam (`<!---->`) for their mount points instead.
        $this->assertStringNotContainsString('<ph-button', $content);
        $this->assertStringNotContainsString('<ph-data-table', $content);
        $this->assertStringNotContainsString('<ph-modal', $content);
        $this->assertStringContainsString('<!---->', $content);

        // The old "style analysis" sections must be gone.
        $this->assertStringNotContainsString('id="tokens"', $content);
        $this->assertStringNotContainsString('--bs-primary', $content);
        $this->assertStringNotContainsString('Design Tokens', $content);
        $this->assertStringNotContainsString('Layout Utilities', $content);

        // Hydration contract: the shipped client module still carries the raw
        // template (same template string the server rendered — Contract 3.5).
        // The template is JSON-escaped in the module (`\u003C` = `<`).
        preg_match('/src="(\/assets\/phue\/Pages\/UI\.[a-f0-9]{8}\.phue\.js)"/', $content, $module);
        $this->assertArrayHasKey(1, $module);

        $moduleScript = $this->app->handle(new Request('GET', $module[1]))->getContent();
        $this->assertStringContainsString('createSSRApp', $moduleScript);
        $this->assertStringContainsString(".mount('#app')", $moduleScript);
        $this->assertStringContainsString('\u003Cph-button', $moduleScript);
        $this->assertStringContainsString('\u003Cph-data-table', $moduleScript);
        $this->assertStringContainsString('\u003Cph-modal', $moduleScript);
    }

    public function testUiRouteWhitelistsOnlyShowcasedComponents(): void
    {
        $content = $this->app->handle(new Request('GET', '/ui'))->getContent();

        preg_match('/window\.__INITIAL_STATE__ = (\{.*?\});/s', $content, $state);
        $this->assertArrayHasKey(1, $state);

        $decoded = json_decode($state[1], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('phuiUrls', $decoded);

        $expectedWhitelist = [
            'PhButton', 'PhInput', 'PhSelect', 'PhTextarea', 'PhCheckbox', 'PhRadio', 'PhFormField',
            'PhDataTable', 'PhBadge', 'PhAvatar', 'PhCard', 'PhChip',
            'PhAlert', 'PhModal', 'PhProgressBar', 'PhSkeleton',
            'PhTabs', 'PhPagination', 'PhBreadcrumb', 'PhLink',
            'PhText', 'PhHeading', 'PhBox', 'Spacer', 'PhStack', 'PhFlex',
        ];

        $this->assertSame($expectedWhitelist, array_keys($decoded['phuiUrls']));

        foreach ($decoded['phuiUrls'] as $name => $url) {
            $this->assertStringStartsWith("/assets/phue/{$name}.", $url);
            $this->assertStringEndsWith('.phue.js', $url);
        }

        // HTML-first components need no client import.
        $this->assertArrayNotHasKey('PhNavigation', $decoded['phuiUrls']);

        // ...but the SSR demo IS rendered server-side.
        $this->assertStringContainsString('nav nav-pills', $content);
    }
}
