<?php

declare(strict_types=1);

namespace Phue\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Phue\Auth\Authenticator\BearerTokenAuthenticator;
use Phue\Auth\Authenticator\SessionAuthenticator;
use Phue\Auth\AuthManager;
use Phue\Auth\IdentityInterface;
use Phue\Auth\Middleware\AccessControlMiddleware;
use Phue\Auth\RbacManager;
use Phue\Core\Request;
use Phue\Core\Response;

class DummyUser implements IdentityInterface
{
    public function __construct(
        private readonly int|string $id,
        private readonly ?string $token = null
    ) {}

    public function getId(): int|string
    {
        return $this->id;
    }

    public static function findIdentity(int|string $id): ?IdentityInterface
    {
        return new self($id);
    }

    public function getAuthKey(): ?string
    {
        return $this->token;
    }
}

class AuthTest extends TestCase
{
    public function testAuthManagerAuthenticatesViaChain(): void
    {
        $sessionAuth = new SessionAuthenticator(DummyUser::class, 'user_id');
        
        $tokenAuth = new BearerTokenAuthenticator(function (string $token) {
            return $token === 'valid-secret-token' ? new DummyUser(42, $token) : null;
        });

        $authManager = new AuthManager([$sessionAuth, $tokenAuth]);

        $request = $this->createMock(Request::class);
        $request->method('getHeader')->with('Authorization')->willReturn('Bearer valid-secret-token');

        $identity = $authManager->authenticate($request);

        $this->assertNotNull($identity);
        $this->assertEquals(42, $identity->getId());
        $this->assertTrue($authManager->check());
    }

    public function testRbacInheritanceAndCycleSafety(): void
    {
        $rbac = new RbacManager();

        $rbac->addRole('author', ['posts.create']);
        $rbac->addRole('editor', ['posts.update', 'posts.delete']);
        $rbac->addRole('admin', ['admin.dashboard']);

        $rbac->addChild('admin', 'editor');
        $rbac->addChild('editor', 'author');
        $rbac->addChild('author', 'admin'); // Intentional infinite cycle protection test

        $rbac->assign(10, 'admin');

        $this->assertTrue($rbac->checkAccess(10, 'admin.dashboard'));
        $this->assertTrue($rbac->checkAccess(10, 'posts.update'));
        $this->assertTrue($rbac->checkAccess(10, 'posts.create'));
        $this->assertFalse($rbac->checkAccess(10, 'system.root'));
    }

    public function testAccessControlMiddlewareEnforcesPermissions(): void
    {
        $rbac = new RbacManager();
        $rbac->addRole('user', ['profile.view']);
        $rbac->assign(1, 'user');

        $user = new DummyUser(1);

        $authUnauthenticated = new AuthManager([]);
        $authAuthenticated = new AuthManager([]);
        $authAuthenticated->login($user);

        $middleware = new AccessControlMiddleware($authAuthenticated, $rbac, 'profile.view');
        $unauthMiddleware = new AccessControlMiddleware($authUnauthenticated, $rbac, 'profile.view');
        $forbiddenMiddleware = new AccessControlMiddleware($authAuthenticated, $rbac, 'admin.view');

        $request = $this->createMock(Request::class);
        $next = fn(Request $req) => Response::json(['status' => 'ok'], 200);

        // 1. Unauthenticated Request -> 401
        $res401 = $unauthMiddleware->handle($request, $next);
        $this->assertEquals(401, $res401->getStatusCode());

        // 2. Insufficient Permission -> 403
        $res403 = $forbiddenMiddleware->handle($request, $next);
        $this->assertEquals(403, $res403->getStatusCode());

        // 3. Authorized Request -> 200
        $res200 = $middleware->handle($request, $next);
        $this->assertEquals(200, $res200->getStatusCode());
    }
}