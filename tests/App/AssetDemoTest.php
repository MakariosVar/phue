<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Frontend\ComponentLoaderRegistry;
use Phue\Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end contract tests for the no-build Vue demo page: the WebController
 * renders self-contained inline-module .phue pages via ComponentRenderer, with
 * the storage/assets vendor source served through the auto-registered
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
        $this->assertStringContainsString('Phue', $title[1]);
        $this->assertStringContainsString('<div id="app">', $content);
        $this->assertStringContainsString('window.__INITIAL_STATE__', $content);
    }

    public function testDemoPageEmbedsExtractedModuleScriptUrl(): void
    {
        $content = $this->app->handle(new Request('GET', '/'))->getContent();

        $this->assertMatchesRegularExpression(
            '/<script type="module" src="\/assets\/phue\/Pages\/Home\.[a-f0-9]{8}\.phue\.js"><\/script>/',
            $content
        );
        $this->assertStringNotContainsString('<script type="module">', $content);
        $this->assertStringNotContainsString('src="/assets/app.js"', $content);
        $this->assertStringNotContainsString('createApp', $content);
    }

    public function testDemoPageServesExtractedModuleScript(): void
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

    public function testUnknownModuleScriptUrlReturnsNonHtml404(): void
    {
        $response = $this->app->handle(new Request('GET', '/assets/phue/DoesNotExist.00000000.phue.js'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('html', strtolower((string) $response->getHeader('content-type')));
    }

    public function testPhuiComponentModuleIsServedWithTemplateAndScript(): void
    {
        $registry = $this->app->getContainer()->get(ComponentLoaderRegistry::class);
        $url = $registry->moduleUrl('PhButton');

        $this->assertNotNull($url);

        $response = $this->app->handle(new Request('GET', $url));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/javascript; charset=utf-8', $response->getHeader('content-type'));

        $content = $response->getContent();
        $this->assertStringContainsString('__phueTemplate', $content);
        $this->assertStringContainsString("name: 'PhButton'", $content);
        $this->assertStringContainsString('spinner-border', $content);
    }

    public function testPhuiComponentModuleHashMatchesServedContent(): void
    {
        $registry = $this->app->getContainer()->get(ComponentLoaderRegistry::class);
        $url = $registry->moduleUrl('PhButton');
        $this->assertNotNull($url);

        $response = $this->app->handle(new Request('GET', $url));
        $content = $response->getContent();

        preg_match('/PhButton\.([a-f0-9]{8})\.phue\.js$/', $url, $m);
        $this->assertSame($m[1], substr(sha1($content), 0, 8));
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