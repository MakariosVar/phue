<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Core\Application;
use Phue\Core\Request;
use PHPUnit\Framework\TestCase;

class SchiiIntegrationTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application('/app');
        $this->app->getRouter()->scanControllers('/app/App/Controllers', 'App\\Controllers');
    }

    public function testSchemaEndpointReturnsJsonSchema(): void
    {
        $request = new Request('GET', '/api/schii/schema/products', ['q' => '']);
        $response = $this->app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $schema = json_decode($response->getContent(), true);
        $this->assertIsArray($schema);
        $this->assertSame('Product', $schema['entity']);
        $this->assertSame('Products', $schema['label']);
        $this->assertSame('box', $schema['icon']);
    }

    public function testSchemaEndpointReturnsListEndpoint(): void
    {
        $request = new Request('GET', '/api/schii/schema/products');
        $response = $this->app->handle($request);

        $schema = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('fields', $schema);
        $this->assertIsArray($schema['fields']);

        $fieldNames = array_column($schema['fields'], 'name');
        $this->assertContains('id', $fieldNames);
        $this->assertContains('name', $fieldNames);
        $this->assertContains('price', $fieldNames);
        $this->assertContains('description', $fieldNames);
    }

    public function testListEndpointRequiresAuthentication(): void
    {
        $request = new Request('GET', '/api/schii/products');
        $response = $this->app->handle($request);

        $this->assertSame(401, $response->getStatusCode());
        $result = json_decode($response->getContent(), true);
        $this->assertSame('Unauthorized', $result['error'] ?? null);
    }

    public function testListEndpointDoesNotFailWithServerError(): void
    {
        $request = new Request('GET', '/api/schii/products');
        $response = $this->app->handle($request);

        $this->assertNotSame(500, $response->getStatusCode());
    }

    public function testListEndpointSearchIsRejectedWithoutAuthentication(): void
    {
        $request = new Request('GET', '/api/schii/products', ['q' => 'test']);
        $response = $this->app->handle($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testListEndpointPaginationIsRejectedWithoutAuthentication(): void
    {
        $request = new Request('GET', '/api/schii/products', ['limit' => 10, 'page' => 1]);
        $response = $this->app->handle($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testUnregisteredEntityReturns404(): void
    {
        $request = new Request('GET', '/api/schii/schema/nonexistent');
        $response = $this->app->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }
}
