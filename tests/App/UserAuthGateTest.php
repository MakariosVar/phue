<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Auth\Authenticator\BearerTokenAuthenticator;
use Phue\Modules\Auth\RbacManager;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use Phue\Core\Request;
use Phue\Modules\Schii\Endpoint\AuthorizedModelEndpoint;
use Phue\Modules\Schii\Endpoint\ModelEndpoint;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Phue\Tests\Support\UsesIsolatedDatabase;

/**
 * Integration test: Bearer token resolves to an AR-backed App\Models\User,
 * and the gate in AuthorizedModelEndpoint uses its DB-loaded roles.
 */
final class UserAuthGateTest extends TestCase
{
    use UsesIsolatedDatabase;

    private Connection $db;
    private SchemaRegistry $registry;
    private string $emptyRbacSeed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = BaseActiveRecord::getConnection();

        // Drop and recreate table for test isolation
        $this->db->execute('DROP TABLE IF EXISTS users');
        $this->db->execute(
            "CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, password_hash TEXT, auth_key TEXT, roles TEXT DEFAULT '[]')"
        );
        $this->registry = new SchemaRegistry();
        $this->emptyRbacSeed = __DIR__ . '/../Schii/Stubs/rbac_empty.php';

        User::create([
            'username' => 'admin',
            'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
            'auth_key' => 'admin-bearer',
            'roles' => '["admin"]',
        ]);
        User::create([
            'username' => 'editor',
            'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
            'auth_key' => 'editor-bearer',
            'roles' => '["editor"]',
        ]);
    }

    private function endpoint(RbacManager $rbac): AuthorizedModelEndpoint
    {
        $authManager = new AuthManager([new BearerTokenAuthenticator(fn (string $token) => User::findIdentityByAuthKey($token))]);
        $delegate = new ModelEndpoint($this->registry, entityNamespace: 'Phue\\Tests\\App\\NoModels');

        return new AuthorizedModelEndpoint($delegate, $authManager, $rbac, $this->registry);
    }

    private function request(string $bearer): Request
    {
        return new Request('GET', '/api/products', [], [], ['Authorization' => "Bearer {$bearer}"]);
    }

    public function testAuthorizedBearerPassesGateAndDelegates(): void
    {
        $rbac = new RbacManager($this->emptyRbacSeed);
        $rbac->addRole('admin', ['admin.Product.read']);

        $response = $this->endpoint($rbac)->index($this->request('admin-bearer'), ['entity' => 'product']);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['error' => 'Resource not found'], (array) json_decode($response->getContent(), true));
    }

    public function testUnauthorizedBearerIsForbidden(): void
    {
        $rbac = new RbacManager($this->emptyRbacSeed);
        $rbac->addRole('admin', ['admin.Product.read']);

        $response = $this->endpoint($rbac)->index($this->request('editor-bearer'), ['entity' => 'product']);
        $this->assertSame(403, $response->getStatusCode());
        $data = (array) json_decode($response->getContent(), true);
        $this->assertSame('Forbidden', $data['error'] ?? null);
    }

    public function testNoBearerReturns401(): void
    {
        $rbac = new RbacManager($this->emptyRbacSeed);
        $rbac->addRole('admin', ['admin.product.read']);

        $response = $this->endpoint($rbac)->index(new Request('GET', '/api/products', [], [], []), ['entity' => 'product']);
        $this->assertSame(401, $response->getStatusCode());
    }
}
