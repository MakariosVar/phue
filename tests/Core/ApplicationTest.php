<?php

namespace Phue\Tests\Core;

use PHPUnit\Framework\TestCase;
use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Core\Response;

// Dummy Controller για το Container Resolution test
class DummyController
{
    public function show(Request $request, array $params): Response
    {
        return new Response("User ID: {$params['id']}");
    }
}

class ApplicationTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application(['debug' => true]);
    }

    public function test_executes_multiple_middlewares_in_correct_onion_order(): void
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

        $this->app->handle(new Request(method: 'GET', uri: '/test'));

        $this->assertEquals([
            'outer_before',
            'inner_before',
            'handler',
            'inner_after',
            'outer_after',
        ], $executionOrder);
    }

    public function test_returns_405_response_with_allow_header(): void
    {
        $this->app->getRouter()->get('/posts', fn () => 'get posts');
        $this->app->getRouter()->post('/posts', fn () => 'create post');

        $request = new Request(method: 'DELETE', uri: '/posts');
        $response = $this->app->handle($request);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, POST', $response->getHeader('Allow'));
    }

    public function test_resolves_controller_string_notation_via_container(): void
    {
        $this->app->getRouter()->get('/users/{id}', DummyController::class . '@show');

        $request = new Request(method: 'GET', uri: '/users/42');
        $response = $this->app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('User ID: 42', $response->getContent());
    }

    public function test_passes_route_params_as_second_argument_to_handler(): void
    {
        $receivedParams = [];

        $this->app->getRouter()->get('/posts/{postId}/comments/{commentId}', function (Request $req, array $params) use (&$receivedParams) {
            $receivedParams = $params;
            return 'OK';
        });

        $request = new Request(method: 'GET', uri: '/posts/10/comments/99');
        $this->app->handle($request);

        $this->assertEquals([
            'postId' => '10',
            'commentId' => '99',
        ], $receivedParams);
    }
}