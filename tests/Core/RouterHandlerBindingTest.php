<?php

declare(strict_types=1);

namespace Phue\Tests\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Application;
use Phue\Core\Container;
use Phue\Core\ResettableInterface;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Tests\Support\RestoresActiveRecordConnection;

/**
 * Contract tests for handler argument binding in Application::dispatchToRouter().
 *
 * Covers: invokable-object handlers (ReflectionMethod::__invoke), type-first array
 * parameter injection (renamed `$params` still works), union-typed path parameters
 * that skip coercion, and the single-flush-point invariant via Application::handle().
 */
#[Group('integration')]
final class RouterHandlerBindingTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application(dirname(__DIR__, 2));
        FlushSpy::$counter = 0;
    }

    // --- Invokable-object handler tests ---

    public function testInvokableObjectHandlerReceivesCoercedPathParams(): void
    {
        $this->app->getRouter()->get('/invokable/{id}', new InvokableShowHandler());
        $response = $this->app->handle(new Request('GET', '/invokable/42'));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame(42, $body['id']);
    }

    public function testInvokableObjectHandlerReturns400ForInvalidIntParam(): void
    {
        $this->app->getRouter()->get('/invokable/{id}', new InvokableShowHandler());
        $response = $this->app->handle(new Request('GET', '/invokable/abc'));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testInvokableObjectHandlerReceivesFullParamsMap(): void
    {
        $this->app->getRouter()->get('/invokable2/{a}/{b}', new InvokableParamsHandler());
        $response = $this->app->handle(new Request('GET', '/invokable2/x/y'));

        $body = json_decode($response->getContent(), true);
        $this->assertSame('x', $body['params']['a']);
        $this->assertSame('y', $body['params']['b']);
    }

    // --- Array-typed parameter convention (Step 5) ---

    public function testRenamedArrayParamReceivesRouteParams(): void
    {
        $this->app->getRouter()->get('/renamed/{id}', function (Request $request, array $routeArgs): array {
            return ['routeArgs' => $routeArgs];
        });

        $response = $this->app->handle(new Request('GET', '/renamed/7'));
        $body = json_decode($response->getContent(), true);

        $this->assertSame('7', $body['routeArgs']['id']);
    }

    public function testLegacyParamsArrayParamStillReceivesRouteParams(): void
    {
        $this->app->getRouter()->get('/legacy/{id}', function (Request $request, array $params): array {
            return ['params' => $params];
        });

        $response = $this->app->handle(new Request('GET', '/legacy/9'));
        $body = json_decode($response->getContent(), true);

        $this->assertSame('9', $body['params']['id']);
    }

    // --- Union coercion lock (Step 4) ---

    public function testUnionTypedPathParamIsNotCoerced(): void
    {
        $this->app->getRouter()->get('/union/{id}', function (string|int $id): array {
            return ['id' => $id, 'type' => get_debug_type($id)];
        });

        $response = $this->app->handle(new Request('GET', '/union/42'));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame('42', $body['id']);
        $this->assertSame('string', $body['type']);
    }

    public function testUnionTypedNonNumericDoesNotReturn400(): void
    {
        $this->app->getRouter()->get('/union/{id}', function (string|int $id): array {
            return ['id' => $id];
        });

        $response = $this->app->handle(new Request('GET', '/union/abc'));

        $this->assertSame(200, $response->getStatusCode());
    }

    // --- Single flush point invariant ---

    public function testApplicationHandleFlushesScopedServicesExactlyOncePerRequest(): void
    {
        /** @var Container $container */
        $container = $this->app->getContainer();
        $container->scoped(FlushSpy::class);

        $this->app->getRouter()->get('/flush/{n}', function (int $n): string {
            return 'ok';
        });

        // Resolve the scoped spy before each request; handle() must reset it exactly once.
        $container->get(FlushSpy::class);
        $this->app->handle(new Request('GET', '/flush/1'));
        $this->assertSame(1, FlushSpy::$counter);

        $container->get(FlushSpy::class);
        $this->app->handle(new Request('GET', '/flush/2'));
        $this->assertSame(2, FlushSpy::$counter);
    }
}

// --- Helper classes (namespaced to Phue\Tests\Core) ---

final class InvokableShowHandler
{
    public function __invoke(Request $request, int $id): Response
    {
        return Response::json(['id' => $id]);
    }
}

final class InvokableParamsHandler
{
    public function __invoke(Request $request, array $params): Response
    {
        return Response::json(['params' => $params]);
    }
}

final class FlushSpy implements ResettableInterface
{
    public static int $counter = 0;

    public function reset(): void
    {
        self::$counter++;
    }
}
