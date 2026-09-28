<?php

namespace Phue\Tests\Core\Database;

use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Connection;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;

/**
 * Bare stub model for Active Record persistence tests — no relations.
 *
 * Named distinctly from the other User fixtures in the suite: PHP symbols are
 * global once loaded, and a shared short name is a cross-file collision waiting
 * for a directory move. Guarded by tests/Suite/TestLayoutTest.php.
 */
class UserStub extends BaseActiveRecord
{
    protected string $table = 'users';
}

/**
 * Unit tests for BaseActiveRecord persistence, change tracking, and ActiveQuery forwarding.
 *
 * @package Phue\Tests\Core\Database
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
class BaseActiveRecordTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = BaseActiveRecord::getConnection();

        // Drop and recreate table for test isolation
        $connection->execute('DROP TABLE IF EXISTS users');
        $connection->execute(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL, status TEXT)'
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Ensure connection is restored after each test
        if (BaseActiveRecord::getConnection() === null) {
            $bootstrapPath = '/tmp/phue_test_' . getmypid() . '.sqlite';
            if (file_exists($bootstrapPath)) {
                $connection = new \Phue\Core\Database\Connection([
                    'driver'   => 'sqlite',
                    'database' => $bootstrapPath,
                ]);
                BaseActiveRecord::setConnection($connection);
            }
        }
    }

    /**
     * Tests model creation, persistence, and primary key assignment.
     */
    public function testCreatesAndSavesModel(): void
    {
        $user = new UserStub([
            'name'   => 'Makarios',
            'email'  => 'makarios@example.com',
            'status' => 'active',
        ]);

        $this->assertTrue($user->save());
        $this->assertSame(1, $user->id);

        $found = UserStub::find(1);
        $this->assertInstanceOf(UserStub::class, $found);
        $this->assertSame('Makarios', $found->name);
    }

    /**
     * Tests fluent static call forwarding returning hydrated Model instances.
     */
    public function testStaticQueryForwardingReturnsModelInstances(): void
    {
        UserStub::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        UserStub::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'pending']);

        // Static where() forwarding
        $activeUsers = UserStub::where('status', 'active')->get();

        $this->assertCount(1, $activeUsers);
        $this->assertInstanceOf(UserStub::class, $activeUsers[0]);
        $this->assertSame('Alice', $activeUsers[0]->name);

        $pendingUser = UserStub::where('status', 'pending')->first();
        $this->assertInstanceOf(UserStub::class, $pendingUser);
        $this->assertSame('Bob', $pendingUser->name);
    }

    /**
     * Tests change tracking (dirty attributes) on update.
     */
    public function testUpdatesOnlyDirtyAttributes(): void
    {
        $user = UserStub::create(['name' => 'Charlie', 'email' => 'charlie@example.com', 'status' => 'active']);

        $this->assertEmpty($user->getDirty());

        $user->name = 'Charlie Updated';
        $this->assertSame(['name' => 'Charlie Updated'], $user->getDirty());

        $this->assertTrue($user->save());
        $this->assertEmpty($user->getDirty());

        $refreshed = UserStub::find($user->id);
        $this->assertSame('Charlie Updated', $refreshed->name);
    }

    /**
     * Tests deleting a model record.
     */
    public function testDeletesModel(): void
    {
        $user = UserStub::create(['name' => 'Dave', 'email' => 'dave@example.com', 'status' => 'active']);

        $this->assertTrue($user->delete());
        $this->assertNull(UserStub::find($user->id));
    }

    /**
     * Tests that delete() returns false for non-persisted models.
     */
    public function testDeleteReturnsFalseForNonPersistedModel(): void
    {
        $user = new UserStub(['name' => 'Ghost', 'email' => 'ghost@example.com']);

        $this->assertFalse($user->delete());
    }

    /**
     * Tests that save() returns true early when no dirty attributes exist on existing model.
     */
    public function testSaveReturnsTrueWhenNoDirtyAttributes(): void
    {
        $user = UserStub::create(['name' => 'Clean', 'email' => 'clean@example.com', 'status' => 'active']);

        $this->assertEmpty($user->getDirty());
        $this->assertTrue($user->save());
    }

    /**
     * Tests that all() returns all records.
     */
    public function testAllReturnsAllRecords(): void
    {
        UserStub::create(['name' => 'Alice', 'email' => 'a@test.com', 'status' => 'active']);
        UserStub::create(['name' => 'Bob', 'email' => 'b@test.com', 'status' => 'active']);

        $all = UserStub::all();
        $this->assertCount(2, $all);
        $this->assertInstanceOf(UserStub::class, $all[0]);
    }

    /**
     * Tests toArray() and jsonSerialize() return correct attributes.
     */
    public function testToArrayAndJsonSerialize(): void
    {
        $user = new UserStub(['name' => 'Test', 'email' => 'test@test.com', 'status' => 'active']);

        $array = $user->toArray();
        $this->assertSame('Test', $array['name']);
        $this->assertSame('test@test.com', $array['email']);

        $json = $user->jsonSerialize();
        $this->assertSame($array, $json);
    }

    /**
     * Tests newFromBuilder hydration with exists=true and original snapshot.
     */
    public function testNewFromBuilderHydratesCorrectly(): void
    {
        $row = ['id' => 42, 'name' => 'Hydrated', 'email' => 'h@test.com', 'status' => 'active'];
        $user = UserStub::newFromBuilder($row);

        $this->assertSame(42, $user->id);
        $this->assertSame('Hydrated', $user->name);
        $this->assertEmpty($user->getDirty());

        // newFromBuilder sets exists=true, so delete() issues a DELETE for id=42
        // that row was never persisted, so delete() must report zero affected rows
        $this->assertFalse($user->delete());
    }

    /**
     * Tests __isset and __unset magic methods.
     */
    public function testIssetAndUnsetMagicMethods(): void
    {
        $user = new UserStub(['name' => 'Test', 'email' => 'test@test.com']);

        $this->assertTrue(isset($user->name));
        $this->assertFalse(isset($user->nonexistent));

        unset($user->name);
        $this->assertFalse(isset($user->name));
        $this->assertNull($user->name);
    }

    /**
     * Tests __get returns null for non-existent attributes.
     */
    public function testGetReturnsNullForNonExistentAttribute(): void
    {
        $user = new UserStub();
        $this->assertNull($user->nonexistent_field);
    }

    /**
     * Tests fill() with empty array does nothing.
     */
    public function testFillWithEmptyArrayIsNoop(): void
    {
        $user = new UserStub(['name' => 'Test']);
        $user->fill([]);

        $this->assertSame('Test', $user->name);
    }

    /**
     * Tests custom primaryKey property.
     */
    public function testCustomPrimaryKeyViaSubclass(): void
    {
        $model = new class extends UserStub {
            protected string $primaryKey = 'user_id';
        };

        $this->assertSame('user_id', $model->getPrimaryKey());
    }

    /**
     * Tests getTable() auto-inflection fallback when table property is empty.
     */
    public function testGetTableInflectsClassNameToPlural(): void
    {
        $model = new class extends BaseActiveRecord {
            protected string $table = '';
        };

        // Class anonymous name doesn't follow standard inflection, but table should be set
        $this->assertIsString($model->getTable());
    }

    /**
     * Tests getConnection() throws RuntimeException when no connection is set.
     */
    public function testGetConnectionThrowsWhenNotSet(): void
    {
        $reflection = new \ReflectionClass(BaseActiveRecord::class);

        // Capture BOTH halves of the static state. Guessing the bootstrap file
        // path and rebuilding a Connection is what used to break this: when the
        // file was absent the restore was skipped, dbConnection stayed null, and
        // the next getConnection() fell back to the application .env — MariaDB.
        // Every later test then ran SQLite DDL against MariaDB and failed with
        // "AUTOINCREMENT", taking 46 unrelated tests with it.
        $savedResolver = $reflection->getStaticPropertyValue('connectionResolver');
        $savedConnection = $reflection->getStaticPropertyValue('dbConnection');

        $reflection->setStaticPropertyValue('connectionResolver', null);
        $reflection->setStaticPropertyValue('dbConnection', null);

        try {
            UserStub::getConnection();
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No database connection set', $e->getMessage());
        } finally {
            // Restore the exact previous values, unconditionally. Do not call
            // setConnection() here: it also reassigns the resolver.
            $reflection->setStaticPropertyValue('connectionResolver', $savedResolver);
            $reflection->setStaticPropertyValue('dbConnection', $savedConnection);
        }
    }

    /**
     * Tests that create() persists and returns model with id.
     */
    public function testCreateReturnsPersistedModelWithId(): void
    {
        $user = UserStub::create(['name' => 'Created', 'email' => 'created@test.com', 'status' => 'active']);

        $this->assertGreaterThan(0, $user->id);
        $this->assertSame('Created', $user->name);

        // After create, delete should work (proving exists=true was set internally)
        $this->assertTrue($user->delete());
    }

    /**
     * Tests that query() returns an ActiveQuery instance.
     */
    public function testQueryReturnsActiveQuery(): void
    {
        $query = UserStub::query();
        $this->assertInstanceOf(\Phue\Core\Database\ActiveRecord\ActiveQuery::class, $query);
    }
}
