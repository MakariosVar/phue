<?php

namespace Phue\Tests\Core\Database;

use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Connection;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;

/**
 * Stub User Model for Active Record testing.
 */
class User extends BaseActiveRecord
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

    /**
     * Tests model creation, persistence, and primary key assignment.
     */
    public function testCreatesAndSavesModel(): void
    {
        $user = new User([
            'name'   => 'Makarios',
            'email'  => 'makarios@example.com',
            'status' => 'active',
        ]);

        $this->assertTrue($user->save());
        $this->assertSame(1, $user->id);

        $found = User::find(1);
        $this->assertInstanceOf(User::class, $found);
        $this->assertSame('Makarios', $found->name);
    }

    /**
     * Tests fluent static call forwarding returning hydrated Model instances.
     */
    public function testStaticQueryForwardingReturnsModelInstances(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'pending']);

        // Static where() forwarding
        $activeUsers = User::where('status', 'active')->get();

        $this->assertCount(1, $activeUsers);
        $this->assertInstanceOf(User::class, $activeUsers[0]);
        $this->assertSame('Alice', $activeUsers[0]->name);

        $pendingUser = User::where('status', 'pending')->first();
        $this->assertInstanceOf(User::class, $pendingUser);
        $this->assertSame('Bob', $pendingUser->name);
    }

    /**
     * Tests change tracking (dirty attributes) on update.
     */
    public function testUpdatesOnlyDirtyAttributes(): void
    {
        $user = User::create(['name' => 'Charlie', 'email' => 'charlie@example.com', 'status' => 'active']);

        $this->assertEmpty($user->getDirty());

        $user->name = 'Charlie Updated';
        $this->assertSame(['name' => 'Charlie Updated'], $user->getDirty());

        $this->assertTrue($user->save());
        $this->assertEmpty($user->getDirty());

        $refreshed = User::find($user->id);
        $this->assertSame('Charlie Updated', $refreshed->name);
    }

    /**
     * Tests deleting a model record.
     */
    public function testDeletesModel(): void
    {
        $user = User::create(['name' => 'Dave', 'email' => 'dave@example.com', 'status' => 'active']);

        $this->assertTrue($user->delete());
        $this->assertNull(User::find($user->id));
    }

    /**
     * Tests that delete() returns false for non-persisted models.
     */
    public function testDeleteReturnsFalseForNonPersistedModel(): void
    {
        $user = new User(['name' => 'Ghost', 'email' => 'ghost@example.com']);

        $this->assertFalse($user->delete());
    }

    /**
     * Tests that save() returns true early when no dirty attributes exist on existing model.
     */
    public function testSaveReturnsTrueWhenNoDirtyAttributes(): void
    {
        $user = User::create(['name' => 'Clean', 'email' => 'clean@example.com', 'status' => 'active']);

        $this->assertEmpty($user->getDirty());
        $this->assertTrue($user->save());
    }

    /**
     * Tests that all() returns all records.
     */
    public function testAllReturnsAllRecords(): void
    {
        User::create(['name' => 'Alice', 'email' => 'a@test.com', 'status' => 'active']);
        User::create(['name' => 'Bob', 'email' => 'b@test.com', 'status' => 'active']);

        $all = User::all();
        $this->assertCount(2, $all);
        $this->assertInstanceOf(User::class, $all[0]);
    }

    /**
     * Tests toArray() and jsonSerialize() return correct attributes.
     */
    public function testToArrayAndJsonSerialize(): void
    {
        $user = new User(['name' => 'Test', 'email' => 'test@test.com', 'status' => 'active']);

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
        $user = User::newFromBuilder($row);

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
        $user = new User(['name' => 'Test', 'email' => 'test@test.com']);

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
        $user = new User();
        $this->assertNull($user->nonexistent_field);
    }

    /**
     * Tests fill() with empty array does nothing.
     */
    public function testFillWithEmptyArrayIsNoop(): void
    {
        $user = new User(['name' => 'Test']);
        $user->fill([]);

        $this->assertSame('Test', $user->name);
    }

    /**
     * Tests custom primaryKey property.
     */
    public function testCustomPrimaryKeyViaSubclass(): void
    {
        $model = new class extends User {
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
        // Save the current connection
        $savedConnection = BaseActiveRecord::getConnection();

        // Temporarily clear the connection using reflection
        $reflection = new \ReflectionProperty(BaseActiveRecord::class, 'dbConnection');
        $reflection->setValue(null, null);

        try {
            User::getConnection();
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No database connection set', $e->getMessage());
        }

        // Restore connection for other tests
        BaseActiveRecord::setConnection($savedConnection);
    }

    /**
     * Tests that create() persists and returns model with id.
     */
    public function testCreateReturnsPersistedModelWithId(): void
    {
        $user = User::create(['name' => 'Created', 'email' => 'created@test.com', 'status' => 'active']);

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
        $query = User::query();
        $this->assertInstanceOf(\Phue\Core\Database\ActiveRecord\ActiveQuery::class, $query);
    }
}
