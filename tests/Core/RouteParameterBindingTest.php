<?php

declare(strict_types=1);

namespace Phue\Tests\Core;

use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Core\Response;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for route path-parameter binding into controller actions.
 *
 * Locks the behavior of route parameter pass-through, scalar type coercion
 * (int/float/bool), the widely used `array` / `array|string $params`
 * convention, optional parameter defaults, and the HTTP 400 rejection path
 * for invalid scalar values along with its security headers.
 */
class RouteParameterBindingTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application('/app');
    }

    private function handle(string $method, string $uri): Response
    {
        return $this->app->handle(new Request($method, $uri));
    }

    public function testIntegerPathParameterIsCoercedToInt(): void
    {
        $this->app->getRouter()->get('/binding/int/{id}', function (Request $req, int $id): Response {
            return Response::json(['id' => $id, 'type' => get_debug_type($id)]);
        });

        $body = json_decode($this->handle('GET', '/binding/int/42')->getContent(), true);

        $this->assertSame(42, $body['id']);
        $this->assertSame('int', $body['type']);
    }

    public function testFloatPathParameterIsCoercedToFloat(): void
    {
        $this->app->getRouter()->get('/binding/float/{value}', function (Request $req, float $value): Response {
            return Response::json(['value' => $value, 'type' => get_debug_type($value)]);
        });

        $body = json_decode($this->handle('GET', '/binding/float/3.14')->getContent(), true);

        $this->assertSame('float', $body['type']);
        $this->assertEqualsWithDelta(3.14, $body['value'], 0.000001);
    }

    public function testBoolPathParameterIsCoercedFromOne(): void
    {
        $this->app->getRouter()->get('/binding/bool/{flag}', function (Request $req, bool $flag): Response {
            return Response::json(['flag' => $flag, 'type' => get_debug_type($flag)]);
        });

        $body = json_decode($this->handle('GET', '/binding/bool/1')->getContent(), true);

        $this->assertTrue($body['flag']);
        $this->assertSame('bool', $body['type']);
    }

    public function testBoolPathParameterIsCoercedFromZero(): void
    {
        $this->app->getRouter()->get('/binding/bool/{flag}', function (Request $req, bool $flag): Response {
            return Response::json(['flag' => $flag]);
        });

        $body = json_decode($this->handle('GET', '/binding/bool/0')->getContent(), true);

        $this->assertFalse($body['flag']);
    }

    public function testUntypedParamsArrayReceivesAllRouteValues(): void
    {
        $this->app->getRouter()->get('/binding/raw/{entity}/{page}', function (Request $req, array $params): Response {
            return Response::json(['entity' => $params['entity'], 'page' => $params['page']]);
        });

        $body = json_decode($this->handle('GET', '/binding/raw/user/3')->getContent(), true);

        $this->assertSame('user', $body['entity']);
        $this->assertSame('3', $body['page']);
    }

    public function testUnionArrayStringParamsReceivesAllRouteValues(): void
    {
        $this->app->getRouter()->get('/binding/union/{entity}/{page}', function (Request $req, array|string $params): Response {
            return Response::json(['entity' => $params['entity'], 'page' => $params['page']]);
        });

        $body = json_decode($this->handle('GET', '/binding/union/user/7')->getContent(), true);

        $this->assertSame('user', $body['entity']);
        $this->assertSame('7', $body['page']);
    }

    public function testOptionalTypedParamFallsBackToDeclaredDefault(): void
    {
        $this->app->getRouter()->get('/binding/default', function (Request $req, int $page = 5): Response {
            return Response::json(['page' => $page]);
        });

        $body = json_decode($this->handle('GET', '/binding/default')->getContent(), true);

        $this->assertSame(5, $body['page']);
    }

    public function testInvalidIntegerRejectedWith400JsonAndSecurityHeaders(): void
    {
        $this->app->getRouter()->get('/binding/int/{id}', function (int $id): Response {
            return Response::json(['id' => $id]);
        });

        $response = $this->handle('GET', '/binding/int/abc');
        $body = json_decode($response->getContent(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString("must be an integer", $body['error'] ?? '');
        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->getHeader('X-Frame-Options'));
    }
}