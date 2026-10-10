<?php

declare(strict_types=1);

namespace Phue\Tests\Auth;

use App\Controllers\AuthController;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use Phue\Core\Request;
use Phue\Core\UI\ComponentRenderer;
use Phue\Modules\Auth\Authenticator\SessionAuthenticator;
use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Auth\Persistence\SessionLoginStore;
use Phue\Modules\Auth\RbacManager;
use Phue\Modules\Schii\Endpoint\AuthorizedModelEndpoint;
use Phue\Modules\Schii\Endpoint\ModelEndpoint;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Tests\Support\UsesIsolatedDatabase;

#[Group('integration')]
final class SessionAuthFlowTest extends TestCase
{
    use UsesIsolatedDatabase;

    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = BaseActiveRecord::getConnection();

        // Drop and recreate table for test isolation
        $this->db->execute('DROP TABLE IF EXISTS users');
        $this->db->execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            auth_key TEXT,
            roles TEXT,
            deleted_at DATETIME NULL
        )');

        $admin = new User();
        $admin->fill([
            'username' => 'admin',
            'password_hash' => password_hash('secret123', PASSWORD_BCRYPT),
            'auth_key' => 'admin-token',
            'roles' => '["admin"]',
        ]);
        $admin->save();

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testSessionAuthenticatorResolvesIdentityFromSession(): void
    {
        $authenticator = new SessionAuthenticator(User::class, new SessionLoginStore());
        $request = new Request('GET', '/schii');

        $this->assertNull($authenticator->authenticate($request));

        $_SESSION['__user_id'] = 1;
        $user = $authenticator->authenticate($request);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(1, $user->getId());
        $this->assertSame('admin', $user->username);
        $this->assertSame(['admin'], $user->getRoles());
    }

    public function testAuthControllerShowLoginReturnsViewOrRedirects(): void
    {
        $renderer = $this->createMock(ComponentRenderer::class);
        $renderer->expects($this->once())
            ->method('render')
            ->with('Pages/Login')
            ->willReturn(\Phue\Core\Response::html('<div>Login Page</div>'));

        $controller = new AuthController($renderer);

        $req = new Request('GET', '/login');
        $res = $controller->showLogin($req);
        $this->assertSame(200, $res->getStatusCode());

        // When already logged in
        $_SESSION['__user_id'] = 1;
        $resLoggedIn = $controller->showLogin($req);
        $this->assertSame(302, $resLoggedIn->getStatusCode());
        $this->assertSame('/schii', $resLoggedIn->getHeader('Location'));
    }

    public function testAuthControllerLoginRejectsInvalidCredentials(): void
    {
        $renderer = $this->createMock(ComponentRenderer::class);
        $controller = new AuthController($renderer);

        // Missing fields
        $reqEmpty = new Request('POST', '/login', [], ['username' => '', 'password' => ''], ['Accept' => 'application/json']);
        $resEmpty = $controller->login($reqEmpty);
        $this->assertSame(422, $resEmpty->getStatusCode());

        // Wrong password
        $reqBad = new Request('POST', '/login', [], ['username' => 'admin', 'password' => 'wrong'], ['Accept' => 'application/json']);
        $resBad = $controller->login($reqBad);
        $this->assertSame(401, $resBad->getStatusCode());
        $this->assertEmpty($_SESSION['__user_id'] ?? null);
    }

    public function testAuthControllerLoginAuthenticatesAndSetsSession(): void
    {
        $renderer = $this->createMock(ComponentRenderer::class);
        $controller = new AuthController($renderer);

        $req = new Request('POST', '/login', [], ['username' => 'admin', 'password' => 'secret123'], ['Accept' => 'application/json']);
        $res = $controller->login($req);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, $_SESSION['__user_id']);

        $data = json_decode($res->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('/schii', $data['redirect']);
    }

    public function testAuthControllerLogoutClearsSession(): void
    {
        $_SESSION['__user_id'] = 1;

        $renderer = $this->createMock(ComponentRenderer::class);
        $controller = new AuthController($renderer);

        $req = new Request('POST', '/logout');
        $res = $controller->logout($req);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/login', $res->getHeader('Location'));
        $this->assertEmpty($_SESSION['__user_id'] ?? null);
    }

    public function testAuthorizedModelEndpointAllowsAccessWithSessionAuth(): void
    {
        $sessionAuth = new SessionAuthenticator(User::class, new SessionLoginStore());
        $authManager = new AuthManager([$sessionAuth]);

        $rbacManager = new RbacManager();
        $rbacManager->addRole('admin', ['*']);

        $registry = new SchemaRegistry();
        $delegate = new ModelEndpoint($registry, entityNamespace: 'App\\Models');

        $authorizedEndpoint = new AuthorizedModelEndpoint($delegate, $authManager, $rbacManager, $registry);

        $request = new Request('GET', '/api/schii/user');

        // 1. Without session -> 401 Unauthorized
        $resUnauth = $authorizedEndpoint->index($request, ['entity' => 'user']);
        $this->assertSame(401, $resUnauth->getStatusCode());

        // 2. With session -> 200 OK Authorized
        $_SESSION['__user_id'] = 1;
        $resAuth = $authorizedEndpoint->index($request, ['entity' => 'user']);
        $this->assertSame(200, $resAuth->getStatusCode());
    }
}
