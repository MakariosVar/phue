<?php

declare(strict_types=1);

namespace Phue\Tests\App;

use App\Models\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use Phue\Core\Request;
use Phue\Modules\Auth\AuthManager;
use Phue\Modules\Auth\Authenticator\BearerTokenAuthenticator;
use Phue\Modules\Auth\RbacManager;
use Phue\Modules\Schii\Endpoint\AuthorizedModelEndpoint;
use Phue\Modules\Schii\Endpoint\ModelEndpoint;
use Phue\Modules\Schii\Schema\FieldSchema;
use Phue\Modules\Schii\Schema\SchemaRegistry;

/**
 * Regression gate for the authorisation-grant write path.
 *
 * Background
 * ----------
 * This is the live half of the P0-4 chain, under the accepted design
 * (option β): the `roles` COLUMN is the authority that RbacManager trusts,
 * because it is per-user and dynamic. The escalation therefore has exactly
 * one remaining door, and it is the write path:
 *
 *   user with admin.User.update
 *     -> PUT /api/schii/users/1 {"roles":["admin"]}
 *     -> next request: checkAccess(1, 'anything', ['admin']) === true
 *
 * ModelEndpoint::update() builds its accepted key set from the #[Field]
 * attribute (`writable && !readonly`, see ModelEndpoint::extractInputData()),
 * so ANY field declared writable:true is client-settable through the generic
 * CRUD API. App\Models\User::$roles was declared writable: true, which is
 * what made the door reachable.
 *
 * RbacManager itself is deliberately NOT hardened: intersecting the column
 * with config/rbac_seeding.php would stop the column from ever granting
 * anything, which kills dynamic per-user roles entirely and returns 403 on
 * five pre-existing authorization tests. See
 * tests/Auth/RbacPrivilegeEscalationTest.php for that measurement.
 *
 * So the invariant this file owns is: the column that carries an
 * authorisation grant must be unreachable through the generic endpoint.
 * Granting and revoking roles belongs on a dedicated path that checks the
 * actor's own authority over the target.
 *
 * The schema-level assertions and the end-to-end request below assert the
 * same invariant from two directions on purpose. The reflection-level test
 * fails fast and names the offending declaration; the request-level test
 * fails if any OTHER part of the write path starts letting it through, which
 * a field-metadata assertion cannot catch.
 *
 * @package Phue\Tests\App
 */
#[Group('integration')]
#[Group('security')]
final class AuthorisationGrantWritePathTest extends TestCase
{
    private SchemaRegistry $schema;
    private Connection $db;

    /**
     * BaseActiveRecord keeps its connection in static state, so a test that
     * does DROP/CREATE on it corrupts every other test that shares the same
     * process. This test writes real rows through User::create(), so it gets a
     * private in-memory connection and restores the statics afterwards.
     */
    private mixed $previousResolver = null;

    private ?Connection $previousConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema = new SchemaRegistry(
            basePath: dirname(__DIR__, 2),
            cache: null,
            cachePrefix: 'authz_write_path_'
        );

        $this->db = new Connection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $reflection = new \ReflectionClass(BaseActiveRecord::class);

        $resolverProperty = $reflection->getProperty('connectionResolver');
        $connectionProperty = $reflection->getProperty('dbConnection');

        $this->previousResolver = $resolverProperty->getValue();
        $this->previousConnection = $connectionProperty->getValue();

        BaseActiveRecord::setConnectionResolver(fn (): Connection => $this->db);
        BaseActiveRecord::setConnection($this->db);

        $this->db->execute(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, '
            . "password_hash TEXT, auth_key TEXT, roles TEXT DEFAULT '[]', deleted_at DATETIME NULL)"
        );
    }

    protected function tearDown(): void
    {
        $reflection = new \ReflectionClass(BaseActiveRecord::class);

        $resolverProperty = $reflection->getProperty('connectionResolver');
        $resolverProperty->setValue(null, $this->previousResolver);

        $connectionProperty = $reflection->getProperty('dbConnection');
        $connectionProperty->setValue(null, $this->previousConnection);

        parent::tearDown();
    }

    private function seedUser(string $username, string $bearer, string $roles): User
    {
        /** @var User $user */
        $user = User::create([
            'username' => $username,
            'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
            'auth_key' => $bearer,
            'roles' => $roles,
        ]);

        return $user;
    }

    private function field(string $name): FieldSchema
    {
        $fields = $this->schema->get(User::class)->fields;

        $this->assertArrayHasKey($name, $fields, "No #[Field] named [{$name}] on the User model.");

        return $fields[$name];
    }

    /**
     * The generic CRUD endpoint must not accept a write to the roles column.
     *
     * This is the test that fails if someone re-declares $roles as
     * writable: true "to make role management work through the UI".
     */
    #[Group('p0')]
    public function testRolesColumnIsNotWritableThroughTheGenericCrudEndpoint(): void
    {
        $field = $this->field('roles');

        $this->assertFalse(
            $field->writable,
            'User::$roles is client-writable through POST/PUT /api/schii/users. '
            . 'A column that feeds an authorisation decision must be assigned through a '
            . 'dedicated path, not the generic CRUD endpoint.'
        );
    }

    /**
     * The password hash must not be writable either — same class of problem:
     * a client must never be able to choose a credential.
     */
    #[Group('p0')]
    public function testCredentialColumnsAreNotWritable(): void
    {
        foreach (['password_hash', 'auth_key'] as $name) {
            $this->assertFalse(
                $this->field($name)->writable,
                "[{$name}] is client-writable — a client can choose its own credential or token."
            );
        }
    }

    /**
     * The primary key must not reach the write path.
     *
     * The effective rule is the one ModelEndpoint applies: a field is
     * accepted only when `writable && !readonly`. Asserting the raw
     * `writable` flag would be wrong — `id` is declared writable:true plus
     * readonly:true on purpose, and the readonly half is what excludes it.
     */
    #[Group('p0')]
    public function testPrimaryKeyIsNotWritable(): void
    {
        $field = $this->field('id');

        $this->assertFalse(
            $field->writable && !$field->readonly,
            'User::$id passes the endpoint write filter — a POST can pin an arbitrary primary key.'
        );
    }

    /**
     * Every field that reaches the write path must be a plain data column.
     *
     * This is the general form of the three tests above, evaluated against the
     * same expression ModelEndpoint::store()/update() uses to build the
     * accepted key set.
     */
    #[Group('p0')]
    public function testNoAuthorisationOrCredentialFieldReachesTheWritePath(): void
    {
        $mustNotBeWritable = ['id', 'roles', 'password_hash', 'auth_key'];

        foreach ($this->schema->get(User::class)->fields as $name => $field) {
            if (!in_array($name, $mustNotBeWritable, true)) {
                continue;
            }

            $this->assertFalse(
                $field->writable && !$field->readonly,
                "Field [{$name}] passes the endpoint write filter and is client-settable."
            );
        }
    }

    /**
     * Read-only is not enough on its own for a credential: a read-only field
     * is still protected on the write path but must also be protected on the
     * read path. BaseActiveRecord::toArray() handles the read side via
     * $hidden; this asserts the model actually declares it.
     */
    #[Group('p0')]
    public function testCredentialColumnsAreHiddenFromSerialisation(): void
    {
        $hidden = (new \ReflectionClass(User::class))->getDefaultProperties()['hidden'] ?? [];

        foreach (['password_hash', 'auth_key'] as $name) {
            $this->assertContains(
                $name,
                $hidden,
                "[{$name}] is not in User::\$hidden, so toArray() serialises it into every "
                . 'API response. The write path is guarded; the read path is not.'
            );
        }
    }

    // ---------------------------------------------------------------------
    // End-to-end: the actual request, not the field metadata
    // ---------------------------------------------------------------------

    private function authorizedEndpoint(RbacManager $rbac): AuthorizedModelEndpoint
    {
        $authManager = new AuthManager([
            new BearerTokenAuthenticator(
                static fn (string $token): ?User => User::findIdentityByAuthKey($token)
            ),
        ]);

        $delegate = new ModelEndpoint(
            $this->schema,
            entityNamespace: 'App\\Models'
        );

        return new AuthorizedModelEndpoint($delegate, $authManager, $rbac, $this->schema);
    }

    /**
     * THE test. An actor holding only `admin.User.update` sends a real PUT to
     * the generic CRUD endpoint trying to promote the administrator.
     *
     * The response is allowed to be 2xx — silently dropping a non-writable
     * key is a legitimate design and the test must not over-specify it. What
     * is asserted is the only thing that matters: the roles column in the
     * database did not change, and the target's authority did not change.
     */
    #[Group('p0')]
    public function testPrivilegedActorCannotPromoteAnotherUserThroughTheGenericCrudEndpoint(): void
    {
        $this->seedUser('victim', 'victim-bearer', '["editor"]');
        $this->seedUser('attacker', 'attacker-bearer', '["userManager"]');

        $rbac = new RbacManager(__DIR__ . '/../Schii/Stubs/rbac_empty.php');
        $rbac->addRole('userManager', ['admin.User.update']);

        // Precondition: the attacker really does hold the write permission.
        $this->assertTrue(
            $rbac->checkAccess(2, 'admin.User.update', ['userManager']),
            'Precondition: the attacker must hold admin.User.update for this test to mean anything.'
        );

        $response = $this->authorizedEndpoint($rbac)->update(
            new Request(
                'PUT',
                '/api/schii/users/1',
                [],
                ['roles' => ['admin']],
                ['Authorization' => 'Bearer attacker-bearer']
            ),
            ['entity' => 'user', 'id' => 1]
        );

        // A 401/403 means the request never reached the delegate, so the
        // database assertions below would pass for the wrong reason.
        $this->assertNotContains(
            $response->getStatusCode(),
            [401, 403, 404, 500],
            'The request never reached the write path, so this test proves nothing. '
            . 'Status ' . $response->getStatusCode() . ': ' . $response->getContent()
        );

        $victim = User::find(1);
        $this->assertNotNull($victim, 'The victim row disappeared — the write path did something unexpected.');

        $this->assertSame(
            ['editor'],
            $victim->getRoles(),
            'PUT {"roles":["admin"]} reached the roles column through the generic CRUD endpoint. '
            . 'An actor with only admin.User.update promoted themselves to administrator.'
        );

        // The escalation must not survive the request either.
        $this->assertFalse(
            $rbac->checkAccess(1, 'admin.user.delete', ['admin']),
            'The victim retained administrator authority after a rejected escalation attempt.'
        );
    }

    /**
     * The same attempt through create(): a client must not be able to mint a
     * new administrator row either. `extractInputData()` is a different call
     * site than in update(), so it is gated separately.
     */
    #[Group('p0')]
    public function testPrivilegedActorCannotMintAnAdministratorThroughCreate(): void
    {
        $this->seedUser('attacker', 'attacker-bearer', '["userManager"]');

        $rbac = new RbacManager(__DIR__ . '/../Schii/Stubs/rbac_empty.php');
        $rbac->addRole('userManager', ['admin.User.create']);

        $response = $this->authorizedEndpoint($rbac)->store(
            new Request(
                'POST',
                '/api/schii/users',
                [],
                ['username' => 'backdoor', 'roles' => ['admin']],
                ['Authorization' => 'Bearer attacker-bearer']
            ),
            ['entity' => 'user']
        );

        $this->assertNotContains(
            $response->getStatusCode(),
            [401, 403, 404, 500],
            'The request never reached the write path, so this test proves nothing. '
            . 'Status ' . $response->getStatusCode() . ': ' . $response->getContent()
        );

        $this->assertGreaterThan(
            0,
            User::where('username', 'backdoor')->count(),
            'Precondition: the row must have been created, otherwise this test proves nothing.'
        );

        $backdoor = User::where('username', 'backdoor')->first();

        $this->assertNotNull($backdoor, 'The created row could not be read back.');

        $this->assertSame(
            [],
            $backdoor->getRoles(),
            'POST {"roles":["admin"]} created a row that was already an administrator. '
            . 'The roles column is reachable through the create path.'
        );
    }
}
