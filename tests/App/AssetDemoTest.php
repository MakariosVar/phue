<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end contract tests for the no-build Vue demo page: the WebController
 * HTML shell (via HtmlShellRenderer) plus serving of the storage/assets source
 * through the auto-registered AssetsController.
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

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeader('content-type'));
        $this->assertStringContainsString('<title>Phue App - Products</title>', $response->getContent());
        $this->assertStringContainsString('<div id="app">', $response->getContent());
        $this->assertStringContainsString('v-for="p in products"', $response->getContent());
    }

    public function testDemoPageEnqueuesAppJsAsModuleScript(): void
    {
        $response = $this->app->handle(new Request('GET', '/'));

        $this->assertStringContainsString(
            '<script type="module" src="/assets/app.js"></script>',
            $response->getContent()
        );
    }

    public function testDemoPageEmbedsInitialProductsState(): void
    {
        $response = $this->app->handle(new Request('GET', '/'));
        $content = $response->getContent();

        $this->assertStringContainsString('window.__INITIAL_STATE__', $content);
        $this->assertStringContainsString('Widget A', $content);
        $this->assertStringContainsString('Widget B', $content);
        $this->assertStringContainsString('29.99', $content);
    }

    public function testServesAppEntryFromFrontendSource(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/app.js'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/javascript; charset=utf-8', $response->getHeader('content-type'));
        $this->assertStringContainsString('createApp', $response->getContent());
        $this->assertStringContainsString(
            '/assets/vendor/vue3/vue.esm-browser.js',
            $response->getContent()
        );
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