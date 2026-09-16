<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Generator;

use PHPUnit\Framework\TestCase;
use Phue\Core\Routing\Router;
use Phue\Modules\Schii\Endpoint\AuthorizedModelEndpoint;
use Phue\Modules\Schii\Endpoint\ModelEndpoint;
use Phue\Modules\Schii\Generator\RuntimeGenerator;
use Phue\Modules\Schii\Schema\SchemaRegistry;

/**
 * RuntimeGenerator: REST route registration on a real Router and the
 * authorized/unauthorized endpoint resolution contract.
 *
 * @package Phue\Tests\Schii\Generator
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class RuntimeGeneratorTest extends TestCase
{
    private function endpoint(): ModelEndpoint
    {
        return new ModelEndpoint(new SchemaRegistry());
    }

    private function generate(): RuntimeGenerator
    {
        return new RuntimeGenerator(new Router(), $this->endpoint());
    }

    public function testGetRoutePatternsCoversFullCrudSet(): void
    {
        $patterns = $this->generate()->getRoutePatterns('/api/schii', 'user');

        $this->assertSame([
            'GET /api/schii/user',
            'POST /api/schii/user',
            'GET /api/schii/user/{id}',
            'PUT /api/schii/user/{id}',
            'DELETE /api/schii/user/{id}',
        ], array_keys($patterns));

        $this->assertSame(
            ['index', 'store', 'show', 'update', 'destroy'],
            array_values($patterns),
        );
    }

    public function testGetRoutePatternsNormalizesTrailingSlashes(): void
    {
        $patterns = $this->generate()->getRoutePatterns('/api/schii/', '/user/');

        $this->assertSame('GET /api/schii/user', array_key_first($patterns));
    }

    public function testRegisterEntityRegistersDispatchingCrudRoutes(): void
    {
        $router = new Router();
        $endpoint = $this->endpoint();
        $generator = new RuntimeGenerator($router, $endpoint);

        $generator->registerEntity('/api/schii', 'user', User::class);

        $routes = $this->capturedRoutes($router);

        $this->assertCount(5, $routes);
        $this->assertSame('GET', $routes[0]['method']);
        $this->assertSame('/api/schii/user', $routes[0]['path']);
        $this->assertSame([$endpoint, 'index'], $routes[0]['handler']);

        // Every generated path can be dispatched by the router.
        foreach ($routes as $route) {
            $match = $router->dispatch($route['method'], $route['path']);
            $this->assertSame($route['handler'], $match->getHandler());
        }
    }

    public function testRegisterAllRegistersRoutesForEveryDiscoveredModel(): void
    {
        $router = new Router();
        $generator = new RuntimeGenerator($router, $this->endpoint());

        $patterns = $generator->registerAll('/api/schii', 'Phue\Tests\Schii\Stubs');

        // user + profile = 2 models × 5 routes.
        $this->assertCount(10, $patterns);
        $this->assertCount(10, $this->capturedRoutes($router));
    }

    public function testRegisterAllReturnsPatternListForMissingNamespace(): void
    {
        $router = new Router();
        $generator = new RuntimeGenerator($router, $this->endpoint());

        $patterns = $generator->registerAll('/api/schii', 'Phue\Tests\Schii\DoesNotExist');

        $this->assertSame([], $patterns);
        $this->assertCount(0, $this->capturedRoutes($router));
    }

    public function testRegisterEntityKeepsAuthorizedEndpointWhenAuthRequired(): void
    {
        $router = new Router();
        $authorized = $this->fakeAuthorizedEndpoint();
        $generator = new RuntimeGenerator($router, $authorized);

        $generator->registerEntity('/api/schii', 'user', User::class, requireAuth: true);

        $routes = $this->capturedRoutes($router);
        $this->assertNotCount(0, $routes);
        $this->assertSame($authorized, $routes[0]['handler'][0]);
    }

    public function testRegisterEntityFallsBackToPlainEndpointWhenAuthDisabled(): void
    {
        $router = new Router();
        $authorized = $this->fakeAuthorizedEndpoint();
        $generator = new RuntimeGenerator($router, $authorized);

        // Must not throw and must swap the authorized endpoint for a plain one.
        $generator->registerEntity('/api/schii', 'user', User::class, requireAuth: false);

        $routes = $this->capturedRoutes($router);
        $this->assertCount(5, $routes);
        $this->assertInstanceOf(ModelEndpoint::class, $routes[0]['handler'][0]);
        $this->assertNotSame($authorized, $routes[0]['handler'][0]);
    }

    /**
     * Returns a real AuthorizedModelEndpoint scaffolded without its dependency
     * tree (only used to prove endpoint resolution in RuntimeGenerator).
     */
    private function fakeAuthorizedEndpoint(): AuthorizedModelEndpoint
    {
        return new class() extends AuthorizedModelEndpoint {
            public function __construct()
            {
                // Threshold-free scaffold: bypass the external dependency graph.
            }
        };
    }

    /**
     * @return list<array{method: string, path: string, handler: mixed, middleware: array}>
     */
    private function capturedRoutes(Router $router): array
    {
        $property = new \ReflectionProperty(Router::class, 'routes');

        $routes = (array) $property->getValue($router);

        return array_map(
            static fn (array $route): array => [
                'method' => $route['method'],
                'path' => $route['path'],
                'handler' => $route['handler'],
                'middleware' => $route['middleware'],
            ],
            $routes,
        );
    }
}