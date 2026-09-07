<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end contract tests for ProductsController route.
 *
 * ProductsController renders Products.phue at /products/.
 * This is distinct from WebController which renders the same
 * component at /.
 *
 * @package Phue\Tests\App
 */
final class ProductsControllerTest extends TestCase
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

    public function testProductsRouteReturnsHtmlShell(): void
    {
        $response = $this->app->handle(new Request('GET', '/products'));
        $content = $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeader('content-type'));
        $this->assertStringContainsString('<div id="app">', $content);
        $this->assertStringContainsString('window.__INITIAL_STATE__', $content);
        $this->assertStringContainsString('<script type="module"', $content);
    }

    public function testProductsRouteTitleContainsProducts(): void
    {
        $response = $this->app->handle(new Request('GET', '/products'));
        $content = $response->getContent();

        preg_match('/<title>(.*?)<\/title>/', $content, $title);
        $this->assertNotNull($title[1] ?? null);
        $this->assertStringContainsString('Products', $title[1]);
    }

    public function testProductsRouteEmbedsProductsModuleScriptUrl(): void
    {
        $content = $this->app->handle(new Request('GET', '/products'))->getContent();

        $this->assertMatchesRegularExpression(
            '/<script type="module" src="\/assets\/phue\/Products\.[a-f0-9]{8}\.phue\.js"><\/script>/',
            $content
        );
    }

    public function testProductsRouteEmbedsProductsData(): void
    {
        $content = $this->app->handle(new Request('GET', '/products'))->getContent();

        preg_match('/window\.__INITIAL_STATE__ = (\{.*?\});/s', $content, $state);
        $this->assertArrayHasKey(1, $state);

        $decoded = json_decode($state[1], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('products', $decoded);
        $this->assertCount(2, $decoded['products']);
        $this->assertSame(['id', 'name', 'price'], array_keys($decoded['products'][0]));
        $this->assertContains('Widget A', array_column($decoded['products'], 'name'));
        $this->assertContains('Widget B', array_column($decoded['products'], 'name'));
        $this->assertStringNotContainsString('<', $decoded['products'][0]['name']);
    }

    public function testProductsRouteEmbedsValue(): void
    {
        $content = $this->app->handle(new Request('GET', '/products'))->getContent();

        preg_match('/window\.__INITIAL_STATE__ = (\{.*?\});/s', $content, $state);
        $this->assertArrayHasKey(1, $state);

        $decoded = json_decode($state[1], true);
        $this->assertSame('value String', $decoded['value']);
    }

    public function testProductsRouteServesProductsModuleScript(): void
    {
        $page = $this->app->handle(new Request('GET', '/products'))->getContent();
        preg_match('/src="(\/assets\/phue\/Products\.[a-f0-9]{8}\.phue\.js)"/', $page, $module);
        $this->assertArrayHasKey(1, $module);

        $response = $this->app->handle(new Request('GET', $module[1]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/javascript; charset=utf-8', $response->getHeader('content-type'));
        $this->assertStringContainsString('createApp', $response->getContent());
        $this->assertStringContainsString(".mount('#app')", $response->getContent());
    }

    public function testProductsRouteContainsVForProducts(): void
    {
        $response = $this->app->handle(new Request('GET', '/products'));
        $content = $response->getContent();

        $this->assertStringContainsString('v-for="p in products"', $content);
    }

    public function testProductsRouteContainsProductDataInHtml(): void
    {
        $response = $this->app->handle(new Request('GET', '/products'));
        $content = $response->getContent();

        $this->assertStringContainsString('Widget A', $content);
        $this->assertStringContainsString('Widget B', $content);
    }
}
