<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Database\ActiveRecord;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;

/**
 * Stub mirroring the shape of App\Models\User: two secret columns and a roles
 * column that a client is allowed to write.
 *
 * @package Phue\Tests\Core\Database\ActiveRecord
 */
class SecretUserStub extends BaseActiveRecord
{
    protected string $table = 'secret_users';

    /**
     * Attributes that must never appear in a serialised payload.
     *
     * @var array<int, string>
     */
    protected array $hidden = ['password_hash', 'auth_key'];
}

/**
 * Stub with no hidden declaration, to prove the filter is opt-in.
 *
 * @package Phue\Tests\Core\Database\ActiveRecord
 */
class PlainRecordStub extends BaseActiveRecord
{
    protected string $table = 'plain_records';
}

/**
 * Stub that is the parent of another stub, to test nested serialisation.
 *
 * @package Phue\Tests\Core\Database\ActiveRecord
 */
class SecretChildStub extends SecretUserStub
{
    protected string $table = 'secret_children';
}

/**
 * Regression gate for secret-attribute exposure in model serialisation.
 *
 * Background
 * ----------
 * BaseActiveRecord::toArray() returns the whole $attributes array verbatim.
 * There is no $hidden / $visible concept anywhere in the class, and
 * ModelEndpoint::index() / ::show() call toArray() directly. So every column
 * of every model is serialised into every API response — including
 * password_hash and live bearer tokens.
 *
 * The `column: false` flag in #[Field] only guards the CSV export path
 * (ModelEndpoint::buildCsv()), which makes the policy contradictory: the same
 * field is treated as a secret when writing, a non-column when exporting, and
 * a plain string when reading over JSON.
 *
 * The fix must introduce a hidden-attribute concept. The tests below pin the
 * *serialisation contract* (what must not appear in a payload) rather than the
 * mechanism, so any correct implementation passes. Only
 * testHiddenIsDeclaredAsAnArrayPropertyDependsOnYourChosenMechanism pins the
 * Laravel-style `protected array $hidden` convention, and it is the single
 * test to revisit if a different mechanism is chosen.
 *
 * @package Phue\Tests\Core\Database\ActiveRecord
 */
#[Group('integration')]
#[Group('security')]
final class ModelSerialisationLeakTest extends TestCase
{
    private Connection $connection;

    /**
     * Both static connection holders are saved and restored, because
     * BaseActiveRecord::getConnection() prefers the resolver over the explicit
     * connection. Any test that boots an Application installs a resolver, so
     * calling setConnection() alone is silently ignored depending on test
     * order.
     */
    private mixed $previousResolver = null;

    private ?Connection $previousConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new Connection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);

        $reflection = new \ReflectionClass(BaseActiveRecord::class);

        $resolverProperty = $reflection->getProperty('connectionResolver');
        $connectionProperty = $reflection->getProperty('dbConnection');

        $this->previousResolver = $resolverProperty->getValue(null);
        $this->previousConnection = $connectionProperty->getValue(null);

        BaseActiveRecord::setConnectionResolver(fn (): Connection => $this->connection);
        BaseActiveRecord::setConnection($this->connection);

        $this->connection->execute(
            'CREATE TABLE secret_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                auth_key TEXT,
                roles TEXT
            )'
        );

        $this->connection->execute(
            'CREATE TABLE secret_children (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                auth_key TEXT,
                roles TEXT
            )'
        );

        $this->connection->execute(
            'CREATE TABLE plain_records (id INTEGER PRIMARY KEY AUTOINCREMENT, note TEXT)'
        );

        $this->connection->execute(
            "INSERT INTO secret_users (username, password_hash, auth_key, roles) VALUES
             ('alice', '\$2y\$10\$abcdefghijklmnopqrstuv', 'live-bearer-token-abc123', '[\"admin\"]')"
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

    private function loadAlice(): SecretUserStub
    {
        $user = SecretUserStub::query()->where('username', '=', 'alice')->first();

        $this->assertInstanceOf(SecretUserStub::class, $user);

        return $user;
    }

    // ---------------------------------------------------------------------
    // The defect
    // ---------------------------------------------------------------------

    /**
     * The headline leak: a serialised model must not carry the password hash
     * or the live bearer token.
     */
    #[Group('p0')]
    public function testToArrayDoesNotLeakSecretAttributes(): void
    {
        $payload = $this->loadAlice()->toArray();

        $this->assertArrayNotHasKey(
            'password_hash',
            $payload,
            'toArray() leaked the password hash into an API payload.'
        );
        $this->assertArrayNotHasKey(
            'auth_key',
            $payload,
            'toArray() leaked a live bearer token into an API payload.'
        );
    }

    /**
     * json_encode($model) goes through jsonSerialize() -> toArray(). A model
     * that is accidentally json_encoded anywhere (a log line, an exception
     * context, a debug dump) leaks the same way.
     */
    #[Group('p0')]
    public function testJsonEncodeDoesNotLeakSecretAttributes(): void
    {
        $json = json_encode($this->loadAlice(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('$2y$10$', $json, 'The bcrypt hash reached the JSON payload.');
        $this->assertStringNotContainsString('live-bearer-token', $json, 'A live bearer token reached the JSON payload.');
    }

    /**
     * Nested relations must be filtered with the same rules. A has-many of
     * children is the classic way a top-level filter gets bypassed.
     */
    #[Group('p0')]
    public function testNestedRelationModelsAreAlsoFiltered(): void
    {
        $this->connection->execute(
            "INSERT INTO secret_children (username, password_hash, auth_key, roles)
             VALUES ('bob', '\$2y\$10\$zzzzzzzzzzzzzzzzzzzzzz', 'child-token-xyz', '[]')"
        );

        $parent = $this->loadAlice();
        $child = SecretChildStub::query()->where('username', '=', 'bob')->first();

        $this->assertInstanceOf(SecretChildStub::class, $child);
        $parent->setRelation('children', [$child]);

        $payload = $parent->toArray();

        $this->assertArrayNotHasKey('password_hash', $payload);
        $this->assertIsArray($payload['children'] ?? null);
        $this->assertArrayNotHasKey(
            'password_hash',
            $payload['children'][0] ?? [],
            'A secret attribute leaked through a nested relation.'
        );
        $this->assertStringNotContainsString('child-token-xyz', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * A nested model of a DIFFERENT class must respect its own hidden list, not
     * inherit the parent's. Each model decides its own secrets.
     */
    #[Group('p0')]
    public function testNestedModelUsesItsOwnHiddenList(): void
    {
        $this->connection->execute(
            "INSERT INTO plain_records (note) VALUES ('nothing secret here')"
        );

        $parent = $this->loadAlice();
        $plain = PlainRecordStub::query()->first();

        $this->assertInstanceOf(PlainRecordStub::class, $plain);
        $parent->setRelation('notes', [$plain]);

        $payload = $parent->toArray();

        $this->assertArrayHasKey(
            'note',
            $payload['notes'][0] ?? [],
            'A model without hidden attributes lost its data — the filter is not opt-in.'
        );
    }

    /**
     * Hiding must be a serialisation concern only. The value must still be
     * readable through the normal accessors, otherwise authentication breaks.
     */
    #[Group('p0')]
    public function testHiddenAttributesRemainReadableThroughAccessors(): void
    {
        $user = $this->loadAlice();

        $this->assertSame(
            '$2y$10$abcdefghijklmnopqrstuv',
            $user->getAttribute('password_hash'),
            'Hiding a secret removed it from the model entirely — login would break.'
        );
        $this->assertSame(
            '$2y$10$abcdefghijklmnopqrstuv',
            $user->password_hash,
            'Hiding a secret removed it from property access.'
        );
        $this->assertSame(
            'live-bearer-token-abc123',
            $user->getAttribute('auth_key'),
            'Hiding a bearer token removed it from getAttribute() — token auth would break.'
        );
    }

    // ---------------------------------------------------------------------
    // Non-regression
    // ---------------------------------------------------------------------

    /**
     * Non-hidden columns must survive serialisation untouched.
     */
    #[Group('p0')]
    public function testNonHiddenAttributesSurviveSerialisation(): void
    {
        $payload = $this->loadAlice()->toArray();

        $this->assertArrayHasKey('id', $payload);
        $this->assertArrayHasKey('username', $payload);
        $this->assertSame('alice', $payload['username']);
    }

    /**
     * A model that declares nothing hidden must serialise every attribute.
     * This is the guard that stops a "fix" from defaulting to hide-everything.
     */
    #[Group('p0')]
    public function testModelWithoutHiddenDeclarationSerialisesEveryAttribute(): void
    {
        $this->connection->execute("INSERT INTO plain_records (note) VALUES ('hello')");

        $record = PlainRecordStub::query()->first();

        $this->assertInstanceOf(PlainRecordStub::class, $record);
        $this->assertSame(
            ['id' => 1, 'note' => 'hello'],
            $record->toArray()
        );
    }

    /**
     * Hiding a key that was never set must not create it, and must not emit a
     * null placeholder either.
     */
    #[Group('p0')]
    public function testHiddenKeyThatIsAbsentStaysAbsent(): void
    {
        $record = new SecretUserStub();
        $record->fill(['id' => 1, 'username' => 'nobody']);

        $payload = $record->toArray();

        $this->assertArrayNotHasKey('auth_key', $payload);
        $this->assertArrayNotHasKey('password_hash', $payload);
        $this->assertArrayNotHasKey('roles', $payload);
    }

    /**
     * The filter must be applied on every call, not cached from the first one:
     * a model serialised, then mutated, must not resurrect a secret.
     */
    #[Group('p0')]
    public function testFilterIsAppliedOnEverySerialisationNotJustTheFirst(): void
    {
        $user = $this->loadAlice();

        $first = $user->toArray();
        $this->assertArrayNotHasKey('password_hash', $first);

        $user->setAttribute('password_hash', 'rotated-hash-value');

        $second = $user->toArray();
        $this->assertArrayNotHasKey(
            'password_hash',
            $second,
            'A rotated secret reappeared in the payload after the first serialisation.'
        );
    }

    /**
     * Mechanism contract.
     *
     * Pins the Laravel-style `protected array $hidden` declaration, which is
     * the convention this ORM already mirrors everywhere else (__get, fill,
     * toArray, find, query, BelongsTo/HasMany, with()).
     *
     * If the fix uses a different mechanism (an attribute, a hook method, a
     * schema registry lookup), this is the ONLY test in the class that needs
     * to be rewritten.
     */
    #[Group('p0')]
    public function testHiddenIsDeclaredAsAnArrayPropertyDependsOnYourChosenMechanism(): void
    {
        // The stub's own declaration proves nothing — the base class must
        // provide the concept, otherwise the declaration is inert.
        $base = new \ReflectionClass(BaseActiveRecord::class);

        $this->assertTrue(
            $base->hasProperty('hidden'),
            'BaseActiveRecord declares no $hidden concept, so a model can declare a '
            . 'hidden list that toArray() silently ignores.'
        );
        $this->assertTrue(
            $base->getProperty('hidden')->isProtected(),
            '$hidden must be protected so it is not itself serialised as an attribute.'
        );
        $this->assertSame(
            [],
            $base->getDefaultProperties()['hidden'] ?? null,
            'The default hidden list must be empty — hiding must be opt-in, not hide-everything.'
        );
    }
}
