<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Core\Response;
use PHPUnit\Framework\TestCase;

class AppIntegrationTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application('/app');
        $this->app->getRouter()->scanControllers('/app/App/Controllers', 'App\\Controllers');
    }

    public function testRootRouteReturnsHtml(): void
    {
        $request = new Request('GET', '/');
        $response = $this->app->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<div id="app">', $response->getContent());
        $this->assertStringContainsString('window.__INITIAL_STATE__', $response->getContent());
    }

    public function testApiInfoRouteReturnsJson(): void
    {
        $request = new Request('GET', '/api/info');
        $response = $this->app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertIsArray($content);
        $this->assertSame('success', $content['status']);
        $this->assertSame('Phue', $content['framework']);
    }

    public function testApiProductsRouteReturnsJson(): void
    {
        $request = new Request('GET', '/api/products');
        $response = $this->app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertIsArray($content);
        $this->assertArrayHasKey('message', $content);
    }

    public function testUnknownRouteReturns404(): void
    {
        $request = new Request('GET', '/this-route-does-not-exist');
        $response = $this->app->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testMethodNotAllowedReturns405(): void
    {
        $request = new Request('POST', '/api/info');
        $response = $this->app->handle($request);

        $this->assertSame(405, $response->getStatusCode());
    }

    public function testMethodNotAllowedIncludesAllowHeader(): void
    {
        $request = new Request('POST', '/api/info');
        $response = $this->app->handle($request);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertStringContainsString('GET', $response->getHeader('Allow') ?? '');
    }

    public function testArrayResponseIsNormalizedToJson(): void
    {
        $request = new Request('GET', '/api/info');
        $response = $this->app->handle($request);

        $this->assertSame('application/json', $response->getHeader('Content-Type') ?? '');
    }

    public function testStringResponseReturnsHtmlContent(): void
    {
        $request = new Request('GET', '/');
        $response = $this->app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<div', $response->getContent());
    }

    public function testWebControllerIsReachable(): void
    {
        $request = new Request('GET', '/');
        $response = $this->app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<div', $response->getContent());
    }

    public function testApiControllerRoutePrefixWorks(): void
    {
        $routes = ['/api/info', '/api/products'];
        foreach ($routes as $route) {
            $request = new Request('GET', $route);
            $response = $this->app->handle($request);
            $this->assertSame(200, $response->getStatusCode(), "Route {$route} should return 200");
        }
    }
}
