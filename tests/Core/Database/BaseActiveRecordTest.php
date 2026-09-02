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
class BaseActiveRecordTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = new Connection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);

        BaseActiveRecord::setConnection($this->connection);

        $this->connection->execute(
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
}