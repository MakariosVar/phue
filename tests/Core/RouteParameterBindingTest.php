<?php

declare(strict_types=1);

namespace Phue\Tests\Core;

use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Core\Response;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\RestoresActiveRecordConnection;

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
    use RestoresActiveRecordConnection;

    private Application $app;

    /**
     * The production-error-path tests below depend on debug being off.
     *
     * Leaving this to /app/.env made the suite's green/red state a property of
     * the developer's machine: a checkout with APP_DEBUG=true could not run it,
     * which is how a security assertion quietly stops being run at all. The
     * Application constructor's ?bool $debug argument exists for exactly this,
     * and testInvalidIntegerMessageIsVerboseOnlyInDebug() already proves it
     * overrides config. Pinning it here means the same assertion holds for
     * every developer and in CI, and the debug-specific behaviour stays covered
     * by its own explicit test rather than by ambient state.
     */
    protected function setUp(): void
    {
        $this->app = new Application('/app', debug: false);
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

        $request = new Request('GET', '/binding/int/abc', [], [], ['Accept' => 'application/json']);
        $response = $this->app->handle($request);
        $body = json_decode($response->getContent(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Bad Request', $body['error'] ?? null);
        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->getHeader('X-Frame-Options'));
    }

    /**
     * The 400 body must not carry the binding failure's message in production.
     *
     * This used to assert the opposite — that "must be an integer" IS in the
     * response — which only held because the developer's .env had APP_DEBUG=true.
     * Asserting a debug-only leak as if it were the contract is worse than not
     * testing it: it makes the suite unrunnable with debug off, and it names the
     * dangerous behaviour as the expected one.
     *
     * Application::handle() returns 'Bad Request' unless debug is on, so the
     * security property to pin is the ABSENCE of the internal message.
     */
    public function testInvalidIntegerErrorDoesNotLeakInternalMessageInProduction(): void
    {
        $this->app->getRouter()->get('/binding/leak/{id}', function (int $id): Response {
            return Response::json(['id' => $id]);
        });

        $response = $this->app->handle(
            new Request('GET', '/binding/leak/abc', [], [], ['Accept' => 'application/json'])
        );
        $body = json_decode($response->getContent(), true);

        $this->assertFalse(
            $this->app->isDebug(),
            'This test asserts the production error path, so the application under test must be in production '
            . 'mode. setUp() pins debug:false for exactly this reason — see the docblock there.'
        );

        $this->assertStringNotContainsString('must be an integer', (string) ($body['error'] ?? ''));
        $this->assertStringNotContainsString('binding/leak', (string) ($body['error'] ?? ''));
        $this->assertStringNotContainsString('/app', (string) ($body['error'] ?? ''));
    }

    /**
     * In debug the message is intentionally verbose, so developers can see which
     * parameter failed. This pins that as an explicit, opt-in behaviour rather
     * than an accident of the developer's environment.
     */
    public function testInvalidIntegerMessageIsVerboseOnlyInDebug(): void
    {
        // Two Application instances with different debug states, in one process.
        // This is exactly what the constructor's ?bool $debug argument exists
        // for — before it, $debug was read once from config and Env::load() was
        // guarded per-process, so the verbose branch was unreachable in tests.
        $app = new Application('/app', debug: true);

        $this->assertTrue($app->isDebug(), 'The explicit debug argument must win over config.');

        $app->getRouter()->get('/binding/verbose/{id}', function (int $id): Response {
            return Response::json(['id' => $id]);
        });

        $response = $app->handle(
            new Request('GET', '/binding/verbose/abc', [], [], ['Accept' => 'application/json'])
        );
        $body = json_decode($response->getContent(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('must be an integer', (string) ($body['error'] ?? ''));
    }

    /**
     * The same request, the same process, the opposite debug state.
     *
     * One test with two Applications is worth more than two tests each with
     * their own: it proves the flag is per-instance rather than per-process,
     * which is the property that was actually broken.
     */
    public function testDebugStateIsPerInstanceNotPerProcess(): void
    {
        $production = new Application('/app', debug: false);
        $debug = new Application('/app', debug: true);

        $this->assertFalse($production->isDebug());
        $this->assertTrue($debug->isDebug());

        foreach ([$production, $debug] as $app) {
            $app->getRouter()->get('/pairing/{id}', function (int $id): Response {
                return Response::json(['id' => $id]);
            });
        }

        $errorOf = function (Application $app): string {
            $response = $app->handle(
                new Request('GET', '/pairing/abc', [], [], ['Accept' => 'application/json'])
            );

            $this->assertSame(400, $response->getStatusCode());

            return (string) (json_decode($response->getContent(), true)['error'] ?? '');
        };

        $this->assertSame('Bad Request', $errorOf($production));
        $this->assertStringContainsString('must be an integer', $errorOf($debug));
    }
}