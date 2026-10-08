<?php

declare(strict_types=1);

namespace Phue\Tests\Auth;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Application;
use Phue\Core\Container;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Auth\IdentityInterface;
use Phue\Modules\Auth\Middleware\AccessControlMiddleware;
use Phue\Modules\Auth\RbacManager;
use Phue\Tests\Support\RestoresActiveRecordConnection;

/**
 * Local identity stub.
 *
 * Deliberately NOT reusing DummyUser from AuthTest. Classes in test files are
 * global once loaded, so sharing one across files works only as long as both
 * files are loaded — and the failure mode when they are not is a bare
 * "Class not found" pointing at the wrong file. tests/Suite/TestLayoutTest.php
 * guards the namespace half of this; this is the other half.
 */
final class WiringIdentity implements IdentityInterface
{
    public function __construct(
        private readonly int|string $id,
        private readonly ?string $token = null
    ) {}

    public function getId(): int|string
    {
        return $this->id;
    }

    public function getAuthKey(): ?string
    {
        return $this->token;
    }

    public static function findIdentity(int|string $id): ?IdentityInterface
    {
        return new self($id);
    }
}

/**
 * AccessControlMiddleware must be reachable through the framework's own pipeline.
 *
 * The original suite called ->handle() directly, which is why a middleware with
 * no __invoke() survived: Application resolves an invokable object with
 * `new ReflectionMethod($handler, '__invoke')` and has no catch on that path, so
 * registering this class through use() produced an uncaught ReflectionException.
 * A test that never enters the pipeline cannot catch that.
 *
 * Every test here goes through Application::handle() and a real request. That is
 * the only level at which the wiring is observable.
 *
 * Note on registration: permission-scoped middleware cannot be autowired. The
 * container cannot build AuthManager (it has an untyped $authenticators array)
 * and the required permission is a scalar, so use() must receive an INSTANCE.
 * An object with __invoke() is a callable in PHP, so it satisfies the
 * `callable|string` signature. testContainerCannotAutowireThisMiddleware pins
 * that constraint so the reason is recorded rather than rediscovered.
 *
 * @package Phue\Tests\Auth
 */
#[Group('integration')]
final class AccessControlMiddlewareWiringTest extends TestCase
{
    use RestoresActiveRecordConnection;

    private function rbacWith(array $rolePermissions, int $userId): RbacManager
    {
        $rbac = new RbacManager();
        $rbac->addRole('member', $rolePermissions);
        $rbac->assign($userId, 'member');

        return $rbac;
    }

    private function app(string $basePath = '/app'): Application
    {
        $app = new Application($basePath, debug: false);

        $this->isolateApplicationDatabase($app);

        return $app;
    }

    private function request(string $uri = '/protected'): Request
    {
        return new Request('GET', $uri, [], [], ['Accept' => 'application/json']);
    }

    public function testUnauthenticatedRequestIsRejectedWith401ThroughTheFramework(): void
    {
        $app = $this->app();
        $app->getRouter()->get('/protected', static fn(): Response => Response::json(['ok' => true]));

        $app->use(new AccessControlMiddleware(
            new AuthManager([]),
            $this->rbacWith(['profile.view'], 1),
            'profile.view'
        ));

        $response = $app->handle($this->request());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testMissingPermissionIsRejectedWith403ThroughTheFramework(): void
    {
        $app = $this->app();
        $app->getRouter()->get('/protected', static fn(): Response => Response::json(['ok' => true]));

        $app->use(new AccessControlMiddleware(
            $this->authenticatedManager(1),
            $this->rbacWith(['profile.view'], 1),
            'admin.delete'
        ));

        $response = $app->handle($this->request());

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAuthorizedRequestReachesTheHandler(): void
    {
        $app = $this->app();
        $app->getRouter()->get('/protected', static fn(): Response => Response::json(['ok' => true]));

        $app->use(new AccessControlMiddleware(
            $this->authenticatedManager(1),
            $this->rbacWith(['profile.view'], 1),
            'profile.view'
        ));

        $response = $app->handle($this->request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['ok' => true], json_decode($response->getContent(), true));
    }

    /**
     * The middleware must not short-circuit the rest of the pipeline: an
     * authorized request has to traverse every remaining middleware on the way
     * to the handler, and the handler's response has to survive the trip back.
     */
    public function testMiddlewareComposesWithOtherMiddlewareInTheCorrectOrder(): void
    {
        $order = [];

        $app = $this->app();
        $app->getRouter()->get('/protected', function () use (&$order): Response {
            $order[] = 'handler';

            return Response::json(['ok' => true]);
        });

        $app->use(function (Request $request, callable $next) use (&$order): Response {
            $order[] = 'outer_before';

            return $next($request);
        });

        $app->use(new AccessControlMiddleware(
            $this->authenticatedManager(1),
            $this->rbacWith(['profile.view'], 1),
            'profile.view'
        ));

        $app->use(function (Request $request, callable $next) use (&$order): Response {
            $order[] = 'inner_before';

            return $next($request);
        });

        $response = $app->handle($this->request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['outer_before', 'inner_before', 'handler'], $order);
    }

    /**
     * A rejected request must not reach the handler at all.
     */
    public function testRejectedRequestNeverInvokesTheHandler(): void
    {
        $reached = false;

        $app = $this->app();
        $app->getRouter()->get('/protected', function () use (&$reached): Response {
            $reached = true;

            return Response::json(['ok' => true]);
        });

        $app->use(new AccessControlMiddleware(
            new AuthManager([]),
            $this->rbacWith(['profile.view'], 1),
            'profile.view'
        ));

        $response = $app->handle($this->request());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse($reached, 'A 401 must short-circuit before the route handler.');
    }

    /**
     * Records WHY use() has to take an instance here.
     *
     * Application::use() accepts `callable|string`, and the string branch hands
     * the class to the container. That branch cannot work for this middleware,
     * and the failure is a ContainerException from a dependency three levels
     * down — nothing to do with permissions. Anyone who tries the documented
     * string form will lose time rediscovering this.
     */
    public function testContainerCannotAutowireThisMiddleware(): void
    {
        $container = new Container();

        $this->expectException(\Phue\Core\Exceptions\ContainerException::class);

        $container->get(AccessControlMiddleware::class);
    }

    /**
     * The container failure is about AuthManager's untyped $authenticators, not
     * about the permission scalar. Pinned separately because the two have
     * different fixes: one is an autowiring gap, the other is inherent to
     * per-route permissions.
     */
    public function testAutowireFailsOnAuthManagerBeforeReachingThePermission(): void
    {
        $container = new Container();

        try {
            $container->make(AuthManager::class);
            $this->fail('Expected AuthManager to be un-autowirable.');
        } catch (\Phue\Core\Exceptions\ContainerException $e) {
            $this->assertStringContainsString(
                'authenticators',
                $e->getMessage(),
                'AuthManager must remain un-autowirable; that is why use() takes an instance.'
            );
        }
    }

    private function authenticatedManager(int $userId): AuthManager
    {
        $auth = new AuthManager([]);
        $auth->login(new WiringIdentity($userId));

        return $auth;
    }
}
