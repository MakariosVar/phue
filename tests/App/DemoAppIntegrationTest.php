<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use PHPUnit\Framework\TestCase;
use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Core\Response;
use App\Controllers\HomeController;
use Phue\Tests\Support\RestoresActiveRecordConnection;

/**
 * End-to-End Integration Test for the Phue Demo Web Application.
 *
 * Verifies Front Controller bootstrapping, directory controller scanning,
 * route attribute matching, controller execution, and response normalization.
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
class DemoAppIntegrationTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application(dirname(__DIR__, 2));
        $this->isolateApplicationDatabase($this->app);

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
     * Test Application automatically bootstraps Config and Database Connection from config directory.
     */
    public function testApplicationBootstrapsConfigAndDatabaseConnection(): void
    {
        $container = $this->app->getContainer();

        $this->assertTrue($container->has(\Phue\Core\Config::class));
        $this->assertTrue($container->has(\Phue\Core\Database\Connection::class));

        $config = $container->get(\Phue\Core\Config::class);

        $defaultConnection = $config->get('database.default');
        $this->assertIsString($defaultConnection);
        $this->assertNotSame('', $defaultConnection);
        $this->assertIsArray($config->get("database.connections.{$defaultConnection}"));
    }
}

