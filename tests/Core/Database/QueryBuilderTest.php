<?php

namespace Phue\Tests\Core\Database;

use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Connection;
use Phue\Core\Database\QueryBuilder;

/**
 * Unit tests for QueryBuilder SQL compilation and CRUD execution against SQLite in-memory.
 *
 * @package Phue\Tests\Core\Database
 */
class QueryBuilderTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = new Connection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);

        $this->connection->execute(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL, age INTEGER, status TEXT)'
        );
    }

    /**
     * Tests SQL string generation and parameter binding extraction.
     */
    public function testToSqlCompilation(): void
    {
        $builder = $this->connection->table('users')
            ->select('id', 'name', 'email')
            ->where('status', 'active')
            ->where('age', '>=', 18)
            ->orWhere('status', 'pending')
            ->orderBy('name', 'DESC')
            ->limit(10)
            ->offset(5);

        $expectedSql = 'SELECT id, name, email FROM users WHERE status = ? AND age >= ? OR status = ? ORDER BY name DESC LIMIT 10 OFFSET 5';

        $this->assertSame($expectedSql, $builder->toSql());
        $this->assertSame(['active', 18, 'pending'], $builder->getBindings());
    }

    /**
     * Tests insert, count, get and first operations against real SQLite in-memory database.
     */
    public function testGetAndFirstWithSqlite(): void
    {
        $builder = $this->connection->table('users');

        $builder->insert(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 25, 'status' => 'active']);
        $builder->insert(['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 17, 'status' => 'inactive']);

        // Count test
        $this->assertSame(2, $this->connection->table('users')->count());

        // Get test with filtering
        $results = $this->connection->table('users')->where('status', 'active')->get();
        $this->assertCount(1, $results);
        $this->assertSame('Alice', $results[0]['name']);

        // First test
        $user = $this->connection->table('users')->where('age', '>=', 18)->first();
        $this->assertIsArray($user);
        $this->assertSame('Alice', $user['name']);
    }

    /**
     * Tests update and delete query executions.
     */
    public function testInsertUpdateDelete(): void
    {
        $usersTable = $this->connection->table('users');

        // Insert
        $inserted = $usersTable->insert(['name' => 'Charlie', 'email' => 'charlie@example.com', 'age' => 30, 'status' => 'pending']);
        $this->assertTrue($inserted);

        // Update
        $updatedRows = $this->connection->table('users')
            ->where('email', 'charlie@example.com')
            ->update(['status' => 'active']);

        $this->assertSame(1, $updatedRows);

        $user = $this->connection->table('users')->where('email', 'charlie@example.com')->first();
        $this->assertSame('active', $user['status']);

        // Delete
        $deletedRows = $this->connection->table('users')->where('id', $user['id'])->delete();
        $this->assertSame(1, $deletedRows);
        $this->assertSame(0, $this->connection->table('users')->count());
    }
}