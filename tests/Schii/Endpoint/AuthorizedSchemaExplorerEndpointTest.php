<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Endpoint;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use Phue\Core\Request;
use Phue\Modules\Auth\Authenticator\SessionAuthenticator;
use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Auth\Persistence\SessionLoginStore;
use Phue\Modules\Auth\RbacManager;
use Phue\Modules\Schii\Endpoint\AuthorizedSchemaInspectorEndpoint;
use Phue\Modules\Schii\Endpoint\AuthorizedSchemaSyncEndpoint;
use Phue\Modules\Schii\Endpoint\SchemaInspectorEndpoint;
use Phue\Modules\Schii\Endpoint\SchemaSyncEndpoint;
use Phue\Modules\Schii\Schema\SchemaInspector;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Modules\Schii\Schema\SchemaSyncEngine;
use Phue\Tests\Auth\Stubs\ExplorerAuthUser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * S13 — the authorized schema-explorer wrappers must not only 401 anonymous
 * traffic; an authenticated flow must survive RBAC and reach the delegate.
 *
 * Two latent landmines are pinned here:
 *  - sync()/diff() must mirror SchemaSyncEndpoint::sync/diff(array|string $params)
 *    (the router passes an array, never a bare string).
 *  - The gate must call RbacManager::checkAccess(user, permission, roles),
 *    not the private RbacManager::hasPermission().
 *
 * @package Phue\Tests\Schii\Endpoint
 */
#[Group('integration')]
final class AuthorizedSchemaExplorerEndpointTest extends TestCase
{
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
            roles TEXT
        )');

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function seedUser(string $username, array $roles): ExplorerAuthUser
    {
        $user = new ExplorerAuthUser();
        $user->fill([
            'username' => $username,
            'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
            'auth_key' => $username . '-token',
            'roles' => json_encode($roles),
        ]);
        $user->save();
        return $user;
    }

    private function authManager(): AuthManager
    {
        $authenticator = new SessionAuthenticator(ExplorerAuthUser::class, new SessionLoginStore());
        return new AuthManager([$authenticator]);
    }

    private function inspectorDelegate(): SchemaInspectorEndpoint
    {
        return new SchemaInspectorEndpoint(
            new SchemaRegistry(),
            new SchemaInspector($this->db),
            $this->db,
            'Phue\\Tests\\Schii\\Stubs'
        );
    }

    private function syncDelegate(): SchemaSyncEndpoint
    {
        return new SchemaSyncEndpoint(
            new SchemaSyncEngine(
                new SchemaRegistry(),
                new SchemaInspector($this->db),
                $this->db
            ),
            'Phue\\Tests\\Schii\\Stubs'
        );
    }

    public function testStructureForbiddenRoleGets403(): void
    {
        $this->seedUser('editor', ['editor']);
        $_SESSION['__user_id'] = 1;

        $rbacManager = new RbacManager();
        $rbacManager->addRole('editor', ['admin.guest.read']);

        $endpoint = new AuthorizedSchemaInspectorEndpoint($this->inspectorDelegate(), $this->authManager(), $rbacManager);

        $response = $endpoint->structure(new Request('GET', '/api/schii/schema/user/structure'), ['entity' => 'user']);

        $this->assertSame(403, $response->getStatusCode(), 'an authenticated non-admin must be forbidden');
    }

    public function testSyncAdminReachesDelegate(): void
    {
        $this->seedUser('admin', ['admin']);
        $_SESSION['__user_id'] = 1;

        $rbacManager = new RbacManager();
        $rbacManager->addRole('admin', ['*']);

        $endpoint = new AuthorizedSchemaSyncEndpoint($this->syncDelegate(), $this->authManager(), $rbacManager);

        $response = $endpoint->sync(new Request('POST', '/api/schii/schema/user/sync'), ['entity' => 'user']);

        $this->assertSame(200, $response->getStatusCode(), 'an admin must reach the sync delegate');
    }

    public function testDiffAdminReachesDelegate(): void
    {
        $this->seedUser('admin', ['admin']);
        $_SESSION['__user_id'] = 1;

        $rbacManager = new RbacManager();
        $rbacManager->addRole('admin', ['*']);

        $endpoint = new AuthorizedSchemaSyncEndpoint($this->syncDelegate(), $this->authManager(), $rbacManager);

        $response = $endpoint->diff(new Request('GET', '/api/schii/schema/user/diff'), ['entity' => 'user']);

        $this->assertSame(200, $response->getStatusCode(), 'an admin must reach the diff delegate');
    }
}