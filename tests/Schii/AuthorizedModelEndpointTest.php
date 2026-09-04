<?php

declare(strict_types=1);

namespace Phue\Tests\Schii;

use PHPUnit\Framework\TestCase;
use Phue\Auth\AuthManager;
use Phue\Auth\IdentityInterface;
use Phue\Auth\RbacManager;
use Nyholm\Psr7\ServerRequest;
use Phue\Schii\Endpoint\AuthorizedModelEndpoint;
use Phue\Schii\Schema\SchemaRegistry;

class AuthorizedAdminUser implements IdentityInterface
{
    public function __construct(
        private readonly int|string $id
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
        return 'test-token';
    }
}

/**
 * Tests for AuthorizedModelEndpoint RBAC enforcement.
 *
 * Note: The permission string is built as "{entity}.{action}" where {entity} is the
 * schema entity (class short name, e.g. "StubProduct"), NOT the lowercased table name.
 */
final class AuthorizedModelEndpointTest extends TestCase
{
    private SchemaRegistry $registry;
    private AuthManager $auth;
    private RbacManager $rbac;
    private AuthorizedModelEndpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new SchemaRegistry();
        $this->auth = new AuthManager([]);
        $this->rbac = new RbacManager();
        $this->endpoint = new AuthorizedModelEndpoint($this->registry, $this->auth, $this->rbac);
    }

    private function decodeBody(mixed $psr7Response): array
    {
        $json = $psr7Response->getBody()->getContents();
        return (array) json_decode($json, true);
    }

    public function testReturns403WhenUserIsNotAuthenticated(): void
    {
        $request = new ServerRequest('GET', '/test');
        $response = $this->endpoint->handle($request, StubProduct::class, 'view');

        $this->assertSame(403, $response->getStatusCode());
        $data = $this->decodeBody($response);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Access Denied', $data['error']);
    }

    public function testReturns403WhenUserLacksPermission(): void
    {
        $user = new AuthorizedAdminUser(1);
        $this->auth->login($user);

        $this->rbac->addRole('viewer', ['profile.read']);
        $this->rbac->assign(1, 'viewer');

        $request = new ServerRequest('GET', '/test');
        $response = $this->endpoint->handle($request, StubProduct::class, 'delete');

        $this->assertSame(403, $response->getStatusCode());
        $data = $this->decodeBody($response);
        $this->assertStringContainsString('StubProduct.delete', $data['error']);
    }

    public function testReturns200WhenUserHasPermission(): void
    {
        $user = new AuthorizedAdminUser(42);
        $this->auth->login($user);

        $this->rbac->addRole('admin', ['StubProduct.view', 'StubProduct.delete']);
        $this->rbac->assign(42, 'admin');

        $request = new ServerRequest('GET', '/test');
        $response = $this->endpoint->handle($request, StubProduct::class, 'view');

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->decodeBody($response);
        $this->assertSame('success', $data['status']);
        $this->assertSame('view', $data['action']);
    }

    public function testBuildsCorrectPermissionStringFromEntityName(): void
    {
        $user = new AuthorizedAdminUser(5);
        $this->auth->login($user);

        $this->rbac->addRole('editor', ['StubProduct.update']);
        $this->rbac->assign(5, 'editor');

        $request = new ServerRequest('PUT', '/test');
        $response = $this->endpoint->handle($request, StubProduct::class, 'update');

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->decodeBody($response);
        $this->assertSame('update', $data['action']);
    }

    public function testInheritedRolePermissionIsAccepted(): void
    {
        $user = new AuthorizedAdminUser(10);
        $this->auth->login($user);

        $this->rbac->addRole('author', ['StubProduct.create']);
        $this->rbac->addRole('admin', ['admin.dashboard']);
        $this->rbac->addChild('admin', 'author');
        $this->rbac->assign(10, 'admin');

        $request = new ServerRequest('POST', '/test');
        $response = $this->endpoint->handle($request, StubProduct::class, 'create');

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->decodeBody($response);
        $this->assertSame('create', $data['action']);
    }

    public function testReturns403ForActionWithoutPermission(): void
    {
        $user = new AuthorizedAdminUser(1);
        $this->auth->login($user);

        $this->rbac->addRole('user', ['StubProduct.view']);
        $this->rbac->assign(1, 'user');

        $request = new ServerRequest('DELETE', '/test');
        $response = $this->endpoint->handle($request, StubProduct::class, 'destroy');

        $this->assertSame(403, $response->getStatusCode());
        $data = $this->decodeBody($response);
        $this->assertStringContainsString('StubProduct.destroy', $data['error']);
    }

    public function testReturns403ForDifferentEntityPermission(): void
    {
        $user = new AuthorizedAdminUser(7);
        $this->auth->login($user);

        // User has permission on StubProduct.view but tries Product.view
        $this->rbac->addRole('reader', ['StubProduct.view']);
        $this->rbac->assign(7, 'reader');

        $request = new ServerRequest('GET', '/test');
        // Even for another model class, the permission is entity-specific
        $response = $this->endpoint->handle($request, StubProduct::class, 'view');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPermissionIsDeniedWhenUsingWrongEntityName(): void
    {
        $user = new AuthorizedAdminUser(7);
        $this->auth->login($user);

        // Grant permission with the wrong (lowercase) entity name - should NOT match
        $this->rbac->addRole('reader', ['stubproduct.view']);
        $this->rbac->assign(7, 'reader');

        $request = new ServerRequest('GET', '/test');
        $response = $this->endpoint->handle($request, StubProduct::class, 'view');

        $this->assertSame(403, $response->getStatusCode());
    }
}
