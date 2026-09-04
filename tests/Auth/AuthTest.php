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

#[\PHPUnit\Framework\Attributes\Group('unit')]
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

    // --- AuthManager Extended Tests ---

    public function testLogoutClearsIdentity(): void
    {
        $auth = new AuthManager([]);
        $user = new DummyUser(1);

        $auth->login($user);
        $this->assertTrue($auth->check());
        $this->assertNotNull($auth->getUser());

        $auth->logout();
        $this->assertFalse($auth->check());
        $this->assertNull($auth->getUser());
    }

    public function testGetUserReturnsNullWhenNotAuthenticated(): void
    {
        $auth = new AuthManager([]);
        $this->assertNull($auth->getUser());
    }

    public function testAuthenticateReturnsNullWhenAllAuthenticatorsFail(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getHeader')->willReturn(null);

        $sessionAuth = new SessionAuthenticator(DummyUser::class, 'user_id');
        $tokenAuth = new BearerTokenAuthenticator(fn(string $token) => null);

        $auth = new AuthManager([$sessionAuth, $tokenAuth]);
        $result = $auth->authenticate($request);

        $this->assertNull($result);
        $this->assertFalse($auth->check());
    }

    public function testLoginOverwritesPreviousIdentity(): void
    {
        $auth = new AuthManager([]);

        $auth->login(new DummyUser(1));
        $this->assertSame(1, $auth->getUser()->getId());

        $auth->login(new DummyUser(2));
        $this->assertSame(2, $auth->getUser()->getId());
    }

    // --- BearerTokenAuthenticator Extended Tests ---

    public function testBearerTokenAuthenticatorWithNoAuthHeader(): void
    {
        $authenticator = new BearerTokenAuthenticator(fn(string $t) => new DummyUser(1));

        $request = $this->createMock(Request::class);
        $request->method('getHeader')->with('Authorization')->willReturn(null);

        $this->assertNull($authenticator->authenticate($request));
    }

    public function testBearerTokenAuthenticatorWithEmptyAuthHeader(): void
    {
        $authenticator = new BearerTokenAuthenticator(fn(string $t) => new DummyUser(1));

        $request = $this->createMock(Request::class);
        $request->method('getHeader')->with('Authorization')->willReturn('');

        $this->assertNull($authenticator->authenticate($request));
    }

    public function testBearerTokenAuthenticatorWithNonBearerHeader(): void
    {
        $authenticator = new BearerTokenAuthenticator(fn(string $t) => new DummyUser(1));

        $request = $this->createMock(Request::class);
        $request->method('getHeader')->with('Authorization')->willReturn('Basic abc123');

        $this->assertNull($authenticator->authenticate($request));
    }

    public function testBearerTokenAuthenticatorWithEmptyToken(): void
    {
        $authenticator = new BearerTokenAuthenticator(fn(string $t) => new DummyUser(1));

        $request = $this->createMock(Request::class);
        $request->method('getHeader')->with('Authorization')->willReturn('Bearer ');

        $this->assertNull($authenticator->authenticate($request));
    }

    public function testBearerTokenAuthenticatorWithWhitespaceToken(): void
    {
        $authenticator = new BearerTokenAuthenticator(fn(string $t) => new DummyUser(1));

        $request = $this->createMock(Request::class);
        $request->method('getHeader')->with('Authorization')->willReturn('Bearer   ');

        $this->assertNull($authenticator->authenticate($request));
    }

    public function testBearerTokenAuthenticatorResolverReturnsNull(): void
    {
        $authenticator = new BearerTokenAuthenticator(fn(string $t) => null);

        $request = $this->createMock(Request::class);
        $request->method('getHeader')->with('Authorization')->willReturn('Bearer invalid-token');

        $this->assertNull($authenticator->authenticate($request));
    }

    public function testBearerTokenAuthenticatorResolverReturnsIdentity(): void
    {
        $authenticator = new BearerTokenAuthenticator(function (string $token) {
            return $token === 'valid' ? new DummyUser(99) : null;
        });

        $request = $this->createMock(Request::class);
        $request->method('getHeader')->with('Authorization')->willReturn('Bearer valid');

        $identity = $authenticator->authenticate($request);
        $this->assertNotNull($identity);
        $this->assertSame(99, $identity->getId());
    }

    // --- RbacManager Extended Tests ---

    public function testRbacCheckAccessReturnsFalseForUnassignedUser(): void
    {
        $rbac = new RbacManager();
        $rbac->addRole('admin', ['everything']);

        $this->assertFalse($rbac->checkAccess(999, 'everything'));
    }

    public function testRbacMultipleRoleAssignments(): void
    {
        $rbac = new RbacManager();
        $rbac->addRole('editor', ['posts.update']);
        $rbac->addRole('moderator', ['comments.delete']);

        $rbac->assign(5, 'editor');
        $rbac->assign(5, 'moderator');

        $this->assertTrue($rbac->checkAccess(5, 'posts.update'));
        $this->assertTrue($rbac->checkAccess(5, 'comments.delete'));
        $this->assertFalse($rbac->checkAccess(5, 'admin.access'));
    }

    public function testRbacRoleWithNoPermissions(): void
    {
        $rbac = new RbacManager();
        $rbac->addRole('guest', []);
        $rbac->assign(1, 'guest');

        $this->assertFalse($rbac->checkAccess(1, 'anything'));
    }

    public function testRbacAddRoleOverwritesExistingPermissions(): void
    {
        $rbac = new RbacManager();
        $rbac->addRole('admin', ['old.permission']);
        $rbac->addRole('admin', ['new.permission']);

        $rbac->assign(1, 'admin');

        $this->assertFalse($rbac->checkAccess(1, 'old.permission'));
        $this->assertTrue($rbac->checkAccess(1, 'new.permission'));
    }

    public function testRbacDeepInheritanceChain(): void
    {
        $rbac = new RbacManager();
        $rbac->addRole('viewer', ['posts.read']);
        $rbac->addRole('editor', ['posts.write']);
        $rbac->addRole('admin', ['system.manage']);

        $rbac->addChild('admin', 'editor');
        $rbac->addChild('editor', 'viewer');

        $rbac->assign(1, 'admin');

        $this->assertTrue($rbac->checkAccess(1, 'posts.read'));
        $this->assertTrue($rbac->checkAccess(1, 'posts.write'));
        $this->assertTrue($rbac->checkAccess(1, 'system.manage'));
    }

    // --- SessionAuthenticator Active Session Tests ---

    public function testSessionAuthenticatorReturnsIdentityWhenSessionHasUserId(): void
    {
        $_SESSION['user_id'] = 7;

        $sessionAuth = new SessionAuthenticator(DummyUser::class, 'user_id');
        $request = $this->createMock(Request::class);

        $identity = $sessionAuth->authenticate($request);

        $this->assertNotNull($identity);
        $this->assertSame(7, $identity->getId());
    }

    public function testSessionAuthenticatorReturnsNullWhenSessionHasNoUserId(): void
    {
        $_SESSION = [];

        $sessionAuth = new SessionAuthenticator(DummyUser::class, 'user_id');
        $request = $this->createMock(Request::class);

        $result = $sessionAuth->authenticate($request);

        $this->assertNull($result);
    }
}