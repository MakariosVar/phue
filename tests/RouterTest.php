<?php

namespace Phue\Tests;

use PHPUnit\Framework\TestCase;
use Phue\Core\Routing\Router;
use Phue\Core\Routing\RouteMatch;
use Phue\Core\Routing\Exceptions\RouteNotFoundException;
use Phue\Core\Routing\Exceptions\MethodNotAllowedException;

class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function test_matches_exact_static_route(): void
    {
        $handler = fn () => 'index';
        $this->router->get('/users', $handler);

        $match = $this->router->dispatch('GET', '/users');

        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertSame($handler, $match->getHandler());
        $this->assertEmpty($match->getParams());
    }

    public function test_extracts_named_route_parameters(): void
    {
        $handler = fn () => 'show';
        $this->router->get('/users/{id}', $handler);

        $match = $this->router->dispatch('GET', '/users/42');

        $this->assertSame($handler, $match->getHandler());
        $this->assertEquals(['id' => '42'], $match->getParams());
    }

    public function test_extracts_multiple_route_parameters(): void
    {
        $handler = fn () => 'post_comment';
        $this->router->post('/posts/{postId}/comments/{commentId}', $handler);

        $match = $this->router->dispatch('POST', '/posts/10/comments/99');

        $this->assertEquals([
            'postId' => '10',
            'commentId' => '99',
        ], $match->getParams());
    }

    public function test_throws_route_not_found_exception_for_unknown_uri(): void
    {
        $this->router->get('/users', fn () => 'users');

        $this->expectException(RouteNotFoundException::class);
        $this->router->dispatch('GET', '/non-existent-path');
    }

    public function test_throws_method_not_allowed_exception_when_path_matches_but_method_fails(): void
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

    public function test_normalizes_trailing_and_leading_slashes(): void
    {
        $handler = fn () => 'profile';
        $this->router->get('/user/profile/', $handler);

        $match = $this->router->dispatch('GET', 'user/profile');

        $this->assertSame($handler, $match->getHandler());
    }

    public function test_ignores_query_strings_during_dispatch(): void
    {
        $handler = fn () => 'search';
        $this->router->get('/search', $handler);

        $match = $this->router->dispatch('GET', '/search?q=phue&page=1');

        $this->assertSame($handler, $match->getHandler());
    }
}