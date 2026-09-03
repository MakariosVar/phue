<?php

namespace Phue\Tests\Core;

use PHPUnit\Framework\TestCase;
use Phue\Core\Application;
use Phue\Core\Container;
use Phue\Core\EventDispatcher;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Routing\Attribute\Middleware;
use Phue\Core\Routing\Attribute\Route;
use Phue\Core\Routing\Router;


/**
 * Unit and integration tests for Application core lifecycle, router dispatching, and middleware pipeline.
 *
 * @package Phue\Tests\Core
 */
class ApplicationTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(['debug' => true]);
        
        // Reset static tracker before each test run
        ExecutionTracker::$log = [];
    }

    public function testExecutesMultipleMiddlewaresInCorrectOnionOrder(): void
    {
        $executionOrder = [];

        // Outer Middleware
        $this->app->use(function (Request $req, callable $next) use (&$executionOrder) {
            $executionOrder[] = 'outer_before';
            $response = $next($req);
            $executionOrder[] = 'outer_after';
            return $response;
        });

        // Inner Middleware
        $this->app->use(function (Request $req, callable $next) use (&$executionOrder) {
            $executionOrder[] = 'inner_before';
            $response = $next($req);
            $executionOrder[] = 'inner_after';
            return $response;
        });

        $this->app->getRouter()->get('/test', function () use (&$executionOrder) {
            $executionOrder[] = 'handler';
            return 'OK';
        });

        $this->app->handle(new Request('GET', '/test'));

        $this->assertEquals([
            'outer_before',
            'inner_before',
            'handler',
            'inner_after',
            'outer_after',
        ], $executionOrder);
    }

    public function testReturns405ResponseWithAllowHeader(): void
    {
        $this->app->getRouter()->get('/posts', fn () => 'get posts');
        $this->app->getRouter()->post('/posts', fn () => 'create post');

        $request = new Request('DELETE', '/posts');
        $response = $this->app->handle($request);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, POST', $response->getHeader('Allow'));
    }

    public function testResolvesControllerStringNotationViaContainer(): void
    {
        $this->app->getRouter()->get('/users/{id}', DummyController::class . '@show');

        $request = new Request('GET', '/users/42');
        $response = $this->app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('User ID: 42', $response->getContent());
    }

    public function testPassesRouteParamsAsSecondArgumentToHandler(): void
    {
        $receivedParams = [];

        $this->app->getRouter()->get('/posts/{postId}/comments/{commentId}', function (Request $req, array $params) use (&$receivedParams) {
            $receivedParams = $params;
            return 'OK';
        });

        $request = new Request('GET', '/posts/10/comments/99');
        $this->app->handle($request);

        $this->assertEquals([
            'postId' => '10',
            'commentId' => '99',
        ], $receivedParams);
    }

    public function testRouteMiddlewareExecutionOrder(): void
    {
        // 1. Register Global Middleware
        $this->app->use(function (Request $req, callable $next): Response {
            ExecutionTracker::$log[] = 'Global:Before';
            /** @var Response $response */
            $response = $next($req);
            ExecutionTracker::$log[] = 'Global:After';
            return $response;
        });

        // 2. Register Controller with Class & Method attributes
        $this->app->getRouter()->registerController(DummyMiddlewareController::class);

        // 3. Dispatch Request
        $request = new Request('GET', '/test/middleware');
        $response = $this->app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ActionExecuted', $response->getContent());

        // Assert exact execution flow order
        $expectedOrder = [
            'Global:Before',
            'Class:Before',
            'Method:Before',
            'Action',
            'Method:After',
            'Class:After',
            'Global:After',
        ];

        $this->assertSame($expectedOrder, ExecutionTracker::$log);
    }

    public function testRouteMiddlewareShortCircuitingWhenEarlyReturn(): void
    {
        // 1. Register Controller with blocking middleware
        $this->app->getRouter()->registerController(DummyShortCircuitController::class);

        // 2. Dispatch Request
        $request = new Request('GET', '/test/unauthorized');
        $response = $this->app->handle($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Unauthorized', $response->getContent());

        // Controller Action should NOT have been executed
        $this->assertNotContains('Action', ExecutionTracker::$log);
        $this->assertSame(['ClassMiddleware:Blocked'], ExecutionTracker::$log);
    }

    /**
     * Tests that Application registers its core infrastructure singletons in the Container.
     */
    public function testApplicationRegistersCoreBindingsInContainer(): void
    {
        $container = $this->app->getContainer();

        $this->assertSame($this->app, $container->get(Application::class));
        $this->assertSame($container, $container->get(Container::class));
        $this->assertSame($this->app->getRouter(), $container->get(Router::class));
        $this->assertSame($this->app->getEvents(), $container->get(EventDispatcher::class));
    }
}


/**
 * Dummy Controller for Container Resolution test.
 */
class DummyController
{
    public function show(Request $request, array $params): Response
    {
        return new Response("User ID: {$params['id']}");
    }
}

/**
 * Global static tracker for recording execution order in tests.
 */
class ExecutionTracker
{
    /** @var array<int, string> */
    public static array $log = [];
}

/**
 * Dummy Class-Level Middleware.
 */
class ClassLevelMiddleware
{
    public function __invoke(Request $req, callable $next): Response
    {
        ExecutionTracker::$log[] = 'Class:Before';
        /** @var Response $response */
        $response = $next($req);
        ExecutionTracker::$log[] = 'Class:After';
        return $response;
    }
}

/**
 * Dummy Method-Level Middleware.
 */
class MethodLevelMiddleware
{
    public function __invoke(Request $req, callable $next): Response
    {
        ExecutionTracker::$log[] = 'Method:Before';
        /** @var Response $response */
        $response = $next($req);
        ExecutionTracker::$log[] = 'Method:After';
        return $response;
    }
}

/**
 * Dummy Short-Circuiting Middleware.
 */
class BlockingMiddleware
{
    public function __invoke(Request $req, callable $next): Response
    {
        ExecutionTracker::$log[] = 'ClassMiddleware:Blocked';
        return new Response('Unauthorized', 401);
    }
}

/**
 * Helper Controller for testing execution order.
 */
#[Route('/test')]
#[Middleware(ClassLevelMiddleware::class)]
class DummyMiddlewareController
{
    #[Route('/middleware', methods: 'GET')]
    #[Middleware(MethodLevelMiddleware::class)]
    public function index(): string
    {
        ExecutionTracker::$log[] = 'Action';
        return 'ActionExecuted';
    }
}

/**
 * Helper Controller for testing short-circuiting.
 */
#[Route('/test')]
#[Middleware(BlockingMiddleware::class)]
class DummyShortCircuitController
{
    #[Route('/unauthorized', methods: 'GET')]
    public function index(): string
    {
        ExecutionTracker::$log[] = 'Action';
        return 'ShouldNotBeReached';
    }
}