<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end contract tests for the no-build Vue demo page: the WebController
 * renders the self-contained inline-module Products.phue via ComponentRenderer,
 * with the storage/assets vendor source served through the auto-registered
 * AssetsController.
 *
 * @package Phue\Tests\App
 */
final class AssetDemoTest extends TestCase
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

    public function testDemoPageReturnsHtmlShell(): void
    {
        $response = $this->app->handle(new Request('GET', '/'));
        $content = $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeader('content-type'));

        preg_match('/<title>(.*?)<\/title>/', $content, $title);
        $this->assertNotNull($title[1] ?? null);
        $this->assertStringContainsString('Products', $title[1]);
        $this->assertStringContainsString('<div id="app">', $content);
        $this->assertStringContainsString('v-for="p in products"', $content);
    }

    public function testDemoPageEmbedsInlineModuleScript(): void
    {
        $content = $this->app->handle(new Request('GET', '/'))->getContent();

        $this->assertStringContainsString('<script type="module">', $content);
        $this->assertStringContainsString('createApp', $content);
        $this->assertStringContainsString(".mount('#app')", $content);
        $this->assertStringNotContainsString('src="/assets/app.js"', $content);
        $this->assertSame(1, substr_count($content, '<script type="module">'));
    }

    public function testDemoPageEmbedsInitialProductsState(): void
    {
        $content = $this->app->handle(new Request('GET', '/'))->getContent();

        $this->assertStringContainsString('window.__INITIAL_STATE__', $content);

        preg_match('/window\.__INITIAL_STATE__ = (\{.*?\});/s', $content, $state);
        $this->assertArrayHasKey(1, $state);

        $decoded = json_decode($state[1], true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded['products']);
        $this->assertSame(['id', 'name', 'price'], array_keys($decoded['products'][0]));
        $this->assertContains('Widget A', array_column($decoded['products'], 'name'));
        $this->assertContains('Widget B', array_column($decoded['products'], 'name'));
        $this->assertStringNotContainsString('<', $decoded['products'][0]['name']);
    }

    public function testRemovedAppEntryReturns404(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/app.js'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Not Found', $response->getContent());
    }

    public function testVendorVueModuleIsServed(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/vendor/vue3/vue.esm-browser.js'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/javascript; charset=utf-8', $response->getHeader('content-type'));
        $this->assertStringNotContainsString('Unable to read', $response->getContent());
    }

    public function testAppSourceIsNotReachableUnderVendorPrefix(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/vendor/js/app.js'));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUnknownVendorFileReturns404(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/vendor/vue3/vue.esm-browser.prod.js'));

        $this->assertSame(404, $response->getStatusCode());
    }
}