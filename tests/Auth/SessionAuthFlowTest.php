<?php

declare(strict_types=1);

namespace Phue\Tests\Auth;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use Phue\Core\Request;
use Phue\Modules\Auth\Authenticator\SessionAuthenticator;
use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Auth\Persistence\SessionLoginStore;
use Phue\Modules\Auth\RbacManager;
use Phue\Modules\Schii\Endpoint\AuthorizedModelEndpoint;
use Phue\Modules\Schii\Endpoint\ModelEndpoint;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Tests\Auth\Stubs\User;
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

    public function testAuthorizedModelEndpointAllowsAccessWithSessionAuth(): void
    {
        $sessionAuth = new SessionAuthenticator(User::class, new SessionLoginStore());
        $authManager = new AuthManager([$sessionAuth]);

        $rbacManager = new RbacManager();
        $rbacManager->addRole('admin', ['*']);

        $registry = new SchemaRegistry();
        $delegate = new ModelEndpoint($registry, entityNamespace: 'Phue\\Tests\\Auth\\Stubs');

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
