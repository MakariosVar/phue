<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use PHPUnit\Framework\TestCase;
use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Core\Response;
use App\Controllers\HomeController;
use App\Models\Product;

/**
 * End-to-End Integration Test for the Phue Demo Web Application.
 *
 * Verifies Front Controller bootstrapping, directory controller scanning,
 * route attribute matching, controller execution, and response normalization.
 */
class DemoAppIntegrationTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application(['debug' => true]);

        // Register Demo controllers from App/Controllers directory
        $controllersDir = dirname(__DIR__, 2) . '/App/Controllers';
        $this->app->getRouter()->scanControllers($controllersDir, 'App\\Controllers');
    }

    /**
     * Test GET /api/info returns normalized JSON response with framework metadata.
     */
    public function testGetApiInfoReturnsFrameworkMetadataJson(): void
    {
        $request = new Request('GET', '/api/info');
        $response = $this->app->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/json', $response->getHeader('Content-Type'));

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertEquals('success', $data['status'] ?? null);
        $this->assertEquals('Phue', $data['framework'] ?? null);
        $this->assertEquals('dev-master', $data['version'] ?? null);
    }

    /**
     * Test GET /api/products returns JSON response from HomeController index action.
     */
    public function testGetApiProductsReturnsProductsJson(): void
    {
        $request = new Request('GET', '/api/products');
        $response = $this->app->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/json', $response->getHeader('Content-Type'));

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertEquals('Fetched products successfully!', $data['message'] ?? null);
    }

    /**
     * Test Product ActiveRecord model properties and fillable configuration.
     */
    public function testProductModelConfiguration(): void
    {
        $product = new Product();
        $product->name = 'Phue T-Shirt';
        $product->price = '29.99';

        $this->assertEquals('products', $product->getTable());
        $this->assertEquals('Phue T-Shirt', $product->name);
        $this->assertEquals('29.99', $product->price);
    }
}
