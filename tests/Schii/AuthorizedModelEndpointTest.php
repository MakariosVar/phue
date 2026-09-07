<?php

declare(strict_types=1);

namespace Phue\Tests\Schii;

use PHPUnit\Framework\TestCase;
use Phue\Auth\AuthManager;
use Phue\Auth\Authenticator\AuthenticatorInterface;
use Phue\Auth\IdentityInterface;
use Phue\Auth\RbacManager;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Schii\Endpoint\AuthorizedModelEndpoint;
use Phue\Schii\Endpoint\ModelEndpoint;
use Phue\Schii\Schema\SchemaRegistry;

final class AuthorizedAdminUser implements IdentityInterface
{
    public function __construct(
        private readonly int|string $id,
        private readonly array $roles = []
    ) {}

    public function getId(): int|string
    {
        return $this->id;
    }

    public function getRoles(): array
    {
        return $this->roles;
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

final class StaticAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private readonly ?IdentityInterface $identity
    ) {}

    public function authenticate(Request $request): ?IdentityInterface
    {
        return $this->identity;
    }
}

/**
 * Unit tests for AuthorizedModelEndpoint RBAC gate.
 *
 * Public contract exercised here (router-facing entry points):
 *   index()/show()/store()/update()/delete()
 * Each runs authenticate() -> RBAC check -> delegates to the matching
 * ModelEndpoint action. Permission format: "admin.{entity}.{verb}" where
 * {verb} is normalized via normalizeActionPermission (store->create,
 * list/show->read, update->update, delete->delete).
 *
 * The delegate is a real ModelEndpoint with an EMPTY mapping: the 401/403
 * paths never reach it, while authorized paths prove delegation by returning
 * the delegate's own outcome (a 404 "Resource not found" for unknown entity).
 */
final class AuthorizedModelEndpointTest extends TestCase
{
    private SchemaRegistry $registry;
    private ModelEndpoint $delegate;
    private string $emptyRbacSeed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new SchemaRegistry();
        // Empty mapping + empty namespace: resolveModelClass() returns null so
        // authorized requests reach the real ModelEndpoint without touching the DB.
        $this->delegate = new ModelEndpoint(
            $this->registry,
            entityNamespace: 'Phue\\Tests\\Schii\\NoModels'
        );
        // RbacManager auto-ingests config/rbac_seeding.php by default, which would
        // pre-assign seed users 1/2. Isolate each test from that global state.
        $this->emptyRbacSeed = __DIR__ . '/Stubs/rbac_empty.php';
    }

    private function endpoint(?IdentityInterface $user, ?RbacManager $rbac = null): AuthorizedModelEndpoint
    {
        return new AuthorizedModelEndpoint(
            $this->delegate,
            new AuthManager([new StaticAuthenticator($user)]),
            $rbac ?? new RbacManager($this->emptyRbacSeed),
            $this->registry
        );
    }

    private function decodeBody(Response $response): array
    {
        return (array) json_decode($response->getContent(), true);
    }

    public function testReturns401WhenNoIdentityIsAuthenticated(): void
    {
        $endpoint = $this->endpoint(null);

        $request = new Request('GET', '/api/schii/products');
        $response = $endpoint->index($request, ['entity' => 'product']);

        $this->assertSame(401, $response->getStatusCode());
        $data = $this->decodeBody($response);
        $this->assertSame('Unauthorized', $data['error'] ?? null);
    }

    public function testReturns401WhenNoAuthenticatorsAreConfigured(): void
    {
        $endpoint = new AuthorizedModelEndpoint(
            $this->delegate,
            new AuthManager([]),
            new RbacManager($this->emptyRbacSeed),
            $this->registry
        );

        $request = new Request('GET', '/api/schii/products');
        $response = $endpoint->index($request, ['entity' => 'product']);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testReturns403WhenUserLacksPermission(): void
    {
        $user = new AuthorizedAdminUser(1);
        $rbac = new RbacManager($this->emptyRbacSeed);
        $rbac->addRole('viewer', ['profile.read']);
        $rbac->assign(1, 'viewer');
        $endpoint = $this->endpoint($user, $rbac);

        $request = new Request('GET', '/api/schii/products');
        $response = $endpoint->index($request, ['entity' => 'product']);

        $this->assertSame(403, $response->getStatusCode());
        $data = $this->decodeBody($response);
        $this->assertSame('Forbidden', $data['error'] ?? null);
        $this->assertStringContainsString('admin.product.read', (string) ($data['message'] ?? ''));
    }

    public function testBuildsExactPermissionStringFromEntityAndAction(): void
    {
        $user = new AuthorizedAdminUser(2);
        $rbac = new RbacManager($this->emptyRbacSeed);
        $rbac->addRole('editor', ['admin.product.update']);
        $rbac->assign(2, 'editor');
        $endpoint = $this->endpoint($user, $rbac);

        $request = new Request('PUT', '/api/schii/products/5');
        $response = $endpoint->update($request, ['entity' => 'product']);

        $this->assertNotSame(403, $response->getStatusCode());
    }

    public function testWrongEntityPermissionIsNotAccepted(): void
    {
        $user = new AuthorizedAdminUser(3);
        $rbac = new RbacManager($this->emptyRbacSeed);
        $rbac->addRole('reader', ['admin.products.read']);
        $rbac->assign(3, 'reader');
        $endpoint = $this->endpoint($user, $rbac);

        $request = new Request('GET', '/api/schii/products');
        $response = $endpoint->index($request, ['entity' => 'product']);

        $this->assertSame(403, $response->getStatusCode());
        $data = $this->decodeBody($response);
        $this->assertStringContainsString('admin.product.read', (string) ($data['message'] ?? ''));
    }

    public function testStoreEntryPointMapsToCreatePermission(): void
    {
        $user = new AuthorizedAdminUser(4);
        $rbac = new RbacManager($this->emptyRbacSeed);
        $rbac->addRole('author', ['admin.product.create']);
        $rbac->assign(4, 'author');
        $endpoint = $this->endpoint($user, $rbac);

        $request = new Request('POST', '/api/schii/products');
        $response = $endpoint->store($request, ['entity' => 'product']);

        // 'store' must be normalized to 'create'
        $this->assertNotSame(403, $response->getStatusCode());
    }

    public function testStoreEntryPointFailsWithoutCreatePermission(): void
    {
        $user = new AuthorizedAdminUser(5);
        $rbac = new RbacManager($this->emptyRbacSeed);
        $rbac->addRole('editor', ['admin.product.read']);
        $rbac->assign(5, 'editor');
        $endpoint = $this->endpoint($user, $rbac);

        $request = new Request('POST', '/api/schii/products');
        $response = $endpoint->store($request, ['entity' => 'product']);

        $this->assertSame(403, $response->getStatusCode());
        $data = $this->decodeBody($response);
        $this->assertStringContainsString('admin.product.create', (string) ($data['message'] ?? ''));
    }

    public function testDeleteEntryPointMapsToDeletePermission(): void
    {
        $user = new AuthorizedAdminUser(6);
        $rbac = new RbacManager($this->emptyRbacSeed);
        $rbac->addRole('admin', ['admin.product.delete']);
        $rbac->assign(6, 'admin');
        $endpoint = $this->endpoint($user, $rbac);

        $request = new Request('DELETE', '/api/schii/products/1');
        $response = $endpoint->delete($request, ['entity' => 'product']);

        $this->assertNotSame(403, $response->getStatusCode());
    }

    public function testShowEntryPointMapsToReadPermission(): void
    {
        $user = new AuthorizedAdminUser(7);
        $rbac = new RbacManager($this->emptyRbacSeed);
        $rbac->addRole('reader', ['admin.product.read']);
        $rbac->assign(7, 'reader');
        $endpoint = $this->endpoint($user, $rbac);

        $request = new Request('GET', '/api/schii/products/5');
        $response = $endpoint->show($request, ['entity' => 'product']);

        // 'show' must be normalized to 'read'
        $this->assertNotSame(403, $response->getStatusCode());
    }

    public function testDelegatesToRealEndpointWhenPermissionGranted(): void
    {
        $user = new AuthorizedAdminUser(42);
        $rbac = new RbacManager($this->emptyRbacSeed);
        $rbac->addRole('admin', ['admin.product.read']);
        $rbac->assign(42, 'admin');
        $endpoint = $this->endpoint($user, $rbac);

        $request = new Request('GET', '/api/schii/products');
        $response = $endpoint->index($request, ['entity' => 'product']);

        // Gate passed; real ModelEndpoint.index reached with empty mapping -> 404
        $this->assertNotSame(401, $response->getStatusCode());
        $this->assertNotSame(403, $response->getStatusCode());
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['error' => 'Resource not found'], $this->decodeBody($response));
    }

    public function testPluralEntitySlugNormalizesToSingularPermission(): void
    {
        $user = new AuthorizedAdminUser(8);
        $rbac = new RbacManager($this->emptyRbacSeed);
        $rbac->addRole('admin', ['admin.product.read']);
        $rbac->assign(8, 'admin');
        $endpoint = $this->endpoint($user, $rbac);

        $request = new Request('GET', '/api/schii/products');
        // Plural URL slug 'products' must map to permission admin.product.read
        $response = $endpoint->index($request, ['entity' => 'products']);

        $this->assertNotSame(403, $response->getStatusCode());
    }

    public function testPluralEntitySlugWithoutSingularPermissionIsRejected(): void
    {
        $user = new AuthorizedAdminUser(9);
        $rbac = new RbacManager($this->emptyRbacSeed);
        $rbac->addRole('admin', ['admin.products.read']);
        $rbac->assign(9, 'admin');
        $endpoint = $this->endpoint($user, $rbac);

        $request = new Request('GET', '/api/schii/products');
        $response = $endpoint->index($request, ['entity' => 'products']);

        $this->assertSame(403, $response->getStatusCode());
        $data = $this->decodeBody($response);
        $this->assertStringContainsString('admin.product.read', (string) ($data['message'] ?? ''));
    }
}