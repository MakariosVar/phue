<?php

namespace Phue\Tests\Core;

use PHPUnit\Framework\TestCase;
use Phue\Core\Routing\Attribute\Route;
use Phue\Core\Routing\Exceptions\MethodNotAllowedException;
use Phue\Core\Routing\Exceptions\RouteNotFoundException;
use Phue\Core\Routing\RouteMatch;
use Phue\Core\Routing\Router;
use Phue\Tests\Core\Stubs\DummyScanController;

/**
 * Unit tests for Router class.
 *
 * @package Phue\Tests\Core\Routing
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
    }

    public function testMatchesExactStaticRoute(): void
    {
        $handler = fn () => 'index';
        $this->router->get('/users', $handler);

        $match = $this->router->dispatch('GET', '/users');

        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertSame($handler, $match->getHandler());
        $this->assertEmpty($match->getParams());
    }

    public function testExtractsNamedRouteParameters(): void
    {
        $handler = fn () => 'show';
        $this->router->get('/users/{id}', $handler);

        $match = $this->router->dispatch('GET', '/users/42');

        $this->assertSame($handler, $match->getHandler());
        $this->assertEquals(['id' => '42'], $match->getParams());
    }

    public function testExtractsMultipleRouteParameters(): void
    {
        $handler = fn () => 'post_comment';
        $this->router->post('/posts/{postId}/comments/{commentId}', $handler);

        $match = $this->router->dispatch('POST', '/posts/10/comments/99');

        $this->assertEquals([
            'postId' => '10',
            'commentId' => '99',
        ], $match->getParams());
    }

    public function testThrowsRouteNotFoundExceptionForUnknownUri(): void
    {
        $this->router->get('/users', fn () => 'users');

        $this->expectException(RouteNotFoundException::class);
        $this->router->dispatch('GET', '/non-existent-path');
    }

    public function testThrowsMethodNotAllowedExceptionWhenPathMatchesButMethodFails(): void
    {
        $this->router->get('/users', fn () => 'get_users');
        $this->router->post('/users', fn () => 'post_users');

        try {
            $this->router->dispatch('DELETE', '/users');
            $this->fail('Expected MethodNotAllowedException was not thrown.');
        } catch (MethodNotAllowedException $e) {
            $this->assertSame(405, $e->getCode());
            $this->assertEqualsCanonicalizing(['GET', 'POST'], $e->getAllowedMethods());
        }
    }

    public function testNormalizesTrailingAndLeadingSlashes(): void
    {
        $handler = fn () => 'profile';
        $this->router->get('/user/profile/', $handler);

        $match = $this->router->dispatch('GET', 'user/profile');

        $this->assertSame($handler, $match->getHandler());
    }

    public function testIgnoresQueryStringsDuringDispatch(): void
    {
        $handler = fn () => 'search';
        $this->router->get('/search', $handler);

        $match = $this->router->dispatch('GET', '/search?q=phue&page=1');

        $this->assertSame($handler, $match->getHandler());
    }

    public function testMatchesInlineTypeConstraintsSuccessfully(): void
    {
        $handler = fn () => 'user_by_int_id';
        $this->router->get('/users/{id:int}', $handler);

        // Valid integer ID should match
        $match = $this->router->dispatch('GET', '/users/123');
        $this->assertSame($handler, $match->getHandler());
        $this->assertSame(['id' => '123'], $match->getParams());
    }

    public function testMatchesInlineTypeConstraintsFailsForInvalidType(): void
    {
        $this->router->get('/users/{id:int}', fn () => 'user_by_int_id');

        // Non-integer ID should fail matching
        $this->expectException(RouteNotFoundException::class);
        $this->router->dispatch('GET', '/users/abc');
    }

    public function testMatchesWhereConstraintsSuccessfully(): void
    {
        $handler = fn () => 'user_custom_regex';
        $this->router->get('/users/{id}', $handler, ['id' => '[0-9]{3}']);

        // 3-digit ID matches
        $match = $this->router->dispatch('GET', '/users/999');
        $this->assertSame($handler, $match->getHandler());
    }

    public function testMatchesWhereConstraintsFailsForInvalidRegex(): void
    {
        $this->router->get('/users/{id}', fn () => 'user_custom_regex', ['id' => '[0-9]{3}']);

        // Non-matching regex fails
        $this->expectException(RouteNotFoundException::class);
        $this->router->dispatch('GET', '/users/12');
    }

    public function testStaticRoutesTakePrecedenceOverDynamicRoutes(): void
    {
        $dynamicHandler = fn () => 'dynamic';
        $staticHandler = fn () => 'static';

        // Register dynamic route FIRST to verify sorting works
        $this->router->get('/users/{id}', $dynamicHandler);
        $this->router->get('/users/new', $staticHandler);

        $match = $this->router->dispatch('GET', '/users/new');

        // Should resolve to staticHandler instead of dynamicHandler with id='new'
        $this->assertSame($staticHandler, $match->getHandler());
        $this->assertEmpty($match->getParams());
    }

    public function testRegistersRoutesFromControllerAttributes(): void
    {
        $this->router->registerController(RouterTestStubController::class);

        // 1. Dispatch class-prefix + method route
        $indexMatch = $this->router->dispatch('GET', '/api/v2/products');
        $this->assertSame([RouterTestStubController::class, 'index'], $indexMatch->getHandler());

        // 2. Dispatch dynamic route with constraint registered via attribute
        $showMatch = $this->router->dispatch('GET', '/api/v2/products/456');
        $this->assertSame([RouterTestStubController::class, 'show'], $showMatch->getHandler());
        $this->assertSame(['id' => '456'], $showMatch->getParams());
    }

    public function testScanControllersRegistersRoutesFromDirectory(): void
    {
        // Scans the dedicated Stubs directory for PSR-4 matching controller files
        $this->router->scanControllers(__DIR__ . '/Stubs', 'Phue\\Tests\\Core\\Stubs');

        $match = $this->router->dispatch('GET', '/api/v3/scanned');

        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertSame([DummyScanController::class, 'index'], $match->getHandler());
    }

    /**
     * Test Router compileRouteRegex handles custom regex constraint containing '#' delimiter without PCRE error.
     */
    public function testRouterHandlesCustomRegexWithHashCharacter(): void
    {
        $this->router->get('/tag/{tag:tag}', fn () => 'matched', ['tag' => '[a-zA-Z0-9#]+']);

        $match = $this->router->dispatch('GET', '/tag/php85');

        $this->assertSame(['tag' => 'php85'], $match->getParams());
    }

    /**
      * Test MethodNotAllowedException returns deduplicated list of allowed methods for matching path.
      */
    public function testMethodNotAllowedExceptionReturnsUniqueAllowedMethods(): void
    {
        $this->router->get('/users', fn () => 'list');
        $this->router->post('/users', fn () => 'create');
        $this->router->put('/users', fn () => 'update');

        try {
            $this->router->dispatch('DELETE', '/users');
            $this->fail('Expected MethodNotAllowedException was not thrown.');
        } catch (MethodNotAllowedException $e) {
            $allowed = $e->getAllowedMethods();
            $this->assertCount(3, $allowed);
            $this->assertContains('GET', $allowed);
            $this->assertContains('POST', $allowed);
            $this->assertContains('PUT', $allowed);
        }
    }

    public function testDispatchThrowsRouteNotFoundWhenNoRoutesRegistered(): void
    {
        $this->expectException(RouteNotFoundException::class);
        $this->router->dispatch('GET', '/anything');
    }
}




/**
 * Stub Controller used exclusively for Router integration testing.
 */
#[Route('/api/v2/products')]
class RouterTestStubController
{
    #[Route('', methods: ['GET'])]
    public function index(): void
    {
    }

    #[Route('/{id:int}', methods: ['GET'])]
    public function show(): void
    {
    }
}