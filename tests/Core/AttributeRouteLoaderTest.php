<?php

namespace Phue\Tests\Core;

use PHPUnit\Framework\TestCase;
use Phue\Core\Routing\Attribute\Route;
use Phue\Core\Routing\Loader\AttributeRouteLoader;

/**
 * Unit test for AttributeRouteLoader.
 *
 * @package Phue\Tests\Core\Routing
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
class AttributeRouteLoaderTest extends TestCase
{
    private AttributeRouteLoader $loader;

    /**
     * Sets up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = new AttributeRouteLoader();
    }

    /**
      * Tests loading routes from a controller with class-level prefixes and method-level attributes.
      */
    public function testLoadExtractsRoutesCorrectly(): void
    {
        $routes = $this->loader->load(DummyAttributeController::class);

        // Assert that two routes were extracted from the controller
        $this->assertCount(2, $routes);

        // 1. Assertions for the index() route
        $indexRoute = $routes[0];
        $this->assertSame('/api/v1/users', $indexRoute['path']);
        $this->assertSame(['GET'], $indexRoute['methods']);
        $this->assertSame('api.v1.users.index', $indexRoute['name']);
        $this->assertSame([DummyAttributeController::class, 'index'], $indexRoute['handler']);
        $this->assertEmpty($indexRoute['where']);

        // 2. Assertions for the show() route
        $showRoute = $routes[1];
        $this->assertSame('/api/v1/users/{id}', $showRoute['path']);
        $this->assertSame(['GET', 'POST'], $showRoute['methods']);
        $this->assertSame('api.v1.', $showRoute['name']); // Unnamed method inherits class prefix
        $this->assertSame([DummyAttributeController::class, 'show'], $showRoute['handler']);
        $this->assertSame(['id' => '\d+'], $showRoute['where']);
    }

    /**
      * Tests that load returns empty array for non-instantiable classes (abstract/interface).
      */
    public function testLoadReturnsEmptyForNonInstantiableClass(): void
    {
        $routes = $this->loader->load(\Phue\Tests\Compiler\Fixtures\AbstractAotTargetClass::class);

        $this->assertIsArray($routes);
        $this->assertEmpty($routes);
    }

    /**
      * Tests that scanDirectory returns empty array for non-existent directory.
      */
    public function testScanDirectoryReturnsEmptyForNonExistentDir(): void
    {
        $routes = $this->loader->scanDirectory('/nonexistent/path/12345', 'Phue\\Tests');

        $this->assertIsArray($routes);
        $this->assertEmpty($routes);
    }

    /**
      * Tests that combinePaths handles empty prefix and root path correctly.
      */
    public function testCombinePathsRootCases(): void
    {
        $loader = new \Phue\Core\Routing\Loader\AttributeRouteLoader();

        // combinePaths is private — use reflection to test (setAccessible deprecated in PHP 8.5, no effect since 8.1)
        $reflection = new \ReflectionClass($loader);
        $method = $reflection->getMethod('combinePaths');

        $this->assertSame('/', $method->invoke($loader, '', '/'));
        $this->assertSame('/api/users', $method->invoke($loader, '/api', '/users'));
        $this->assertSame('/api/users', $method->invoke($loader, '/api/', '/users'));
    }
}

/**
 * Helper Controller Class used exclusively for testing AttributeRouteLoader.
 */
#[Route('/api/v1', name: 'api.v1.')]
class DummyAttributeController
{
    /**
     * Index action test route.
     */
    #[Route('/users', methods: 'GET', name: 'users.index')]
    public function index(): void
    {
    }

    /**
     * Show action test route with HTTP methods array and custom constraints.
     */
    #[Route('/users/{id}', methods: ['GET', 'POST'], where: ['id' => '\d+'])]
    public function show(): void
    {
    }
}