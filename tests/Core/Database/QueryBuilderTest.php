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
     * Tests SQL string generation with quoted identifiers and parameter binding extraction.
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

        $expectedSql = 'SELECT `id`, `name`, `email` FROM `users` WHERE `status` = ? AND `age` >= ? OR `status` = ? ORDER BY `name` DESC LIMIT 10 OFFSET 5';

        $this->assertSame($expectedSql, $builder->toSql());
        $this->assertSame(['active', 18, 'pending'], $builder->getBindings());
    }

    /**
     * Tests special identifier cases: table.column, AS alias, and wildcard (*).
     */
    public function testIdentifierQuotingEdgeCases(): void
    {
        $builder = $this->connection->table('users')
            ->select('users.id', 'users.name AS full_name', '*')
            ->where('users.status', 'active');

        $expectedSql = 'SELECT `users`.`id`, `users`.`name` AS `full_name`, * FROM `users` WHERE `users`.`status` = ?';

        $this->assertSame($expectedSql, $builder->toSql());
    }

    /**
     * Tests count behavior when limit/offset are defined (ensures they are temporarily reset).
     */
    public function testCountResetsLimitAndOffset(): void
    {
        $builder = $this->connection->table('users');
        $builder->insert(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 25, 'status' => 'active']);
        $builder->insert(['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 17, 'status' => 'active']);

        $query = $this->connection->table('users')->where('status', 'active')->limit(1)->offset(1);

        $this->assertSame(2, $query->count());
        // Verify state restoration after count()
        $this->assertSame(1, count($query->get()));
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
     * Tests whereIn-style filtering using multiple where clauses.
     */
    public function testMultipleWhereClausesFilterCorrectly(): void
    {
        $builder = $this->connection->table('users');
        $builder->insert(['name' => 'Alice', 'email' => 'alice@test.com', 'age' => 25, 'status' => 'active']);
        $builder->insert(['name' => 'Bob', 'email' => 'bob@test.com', 'age' => 17, 'status' => 'inactive']);
        $builder->insert(['name' => 'Charlie', 'email' => 'charlie@test.com', 'age' => 30, 'status' => 'active']);

        $results = $this->connection->table('users')
            ->where('status', 'active')
            ->where('age', '>=', 18)
            ->get();

        $this->assertCount(2, $results);
    }

    /**
     * Tests first() returns null on empty result set.
     */
    public function testFirstReturnsNullOnEmptyTable(): void
    {
        $result = $this->connection->table('users')
            ->where('status', 'nonexistent')
            ->first();

        $this->assertNull($result);
    }

    /**
     * Tests get() returns empty array on no matches.
     */
    public function testGetReturnsEmptyArrayOnNoMatches(): void
    {
        $results = $this->connection->table('users')
            ->where('name', 'nobody')
            ->get();

        $this->assertIsArray($results);
        $this->assertCount(0, $results);
    }

    /**
     * Tests toSql throws InvalidArgumentException when no table specified.
     */
    public function testToSqlThrowsWithoutTable(): void
    {
        $builder = new QueryBuilder($this->connection);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No table specified');
        $builder->toSql();
    }

    /**
     * Tests insert throws InvalidArgumentException when no table specified.
     */
    public function testInsertThrowsWithoutTable(): void
    {
        $builder = new QueryBuilder($this->connection);

        $this->expectException(\InvalidArgumentException::class);
        $builder->insert(['name' => 'test']);
    }

    /**
     * Tests update throws InvalidArgumentException when no table specified.
     */
    public function testUpdateThrowsWithoutTable(): void
    {
        $builder = new QueryBuilder($this->connection);

        $this->expectException(\InvalidArgumentException::class);
        $builder->update(['name' => 'test']);
    }

    /**
     * Tests delete throws InvalidArgumentException when no table specified.
     */
    public function testDeleteThrowsWithoutTable(): void
    {
        $builder = new QueryBuilder($this->connection);

        $this->expectException(\InvalidArgumentException::class);
        $builder->delete();
    }

    /**
     * Tests orderBy throws InvalidArgumentException for invalid direction.
     */
    public function testOrderByThrowsOnInvalidDirection(): void
    {
        $builder = $this->connection->table('users');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ORDER BY direction');
        $builder->orderBy('name', 'SIDEWAYS');
    }

    /**
     * Tests multiple orderBy clauses.
     */
    public function testMultipleOrderByClauses(): void
    {
        $builder = $this->connection->table('users')
            ->orderBy('status', 'ASC')
            ->orderBy('name', 'DESC');

        $expectedSql = 'SELECT * FROM `users` ORDER BY `status` ASC, `name` DESC';
        $this->assertSame($expectedSql, $builder->toSql());
    }

    /**
     * Tests offset alone in SQL generation.
     */
    public function testOffsetAloneInSql(): void
    {
        $builder = $this->connection->table('users')
            ->offset(10);

        $this->assertStringContainsString('OFFSET 10', $builder->toSql());
        $this->assertStringNotContainsString('LIMIT', $builder->toSql());
    }

    /**
     * Tests select with no arguments defaults to wildcard.
     */
    public function testSelectDefaultsToWildcard(): void
    {
        $builder = $this->connection->table('users')
            ->select();

        $this->assertStringContainsString('SELECT *', $builder->toSql());
    }

    /**
     * Tests count with no rows returns 0.
     */
    public function testCountReturnsZeroOnEmptyTable(): void
    {
        $count = $this->connection->table('users')->count();
        $this->assertSame(0, $count);
    }

    /**
     * Tests orWhere clause generates correct SQL.
     */
    public function testOrWhereClause(): void
    {
        $builder = $this->connection->table('users')
            ->where('name', 'Alice')
            ->orWhere('name', 'Bob');

        $expectedSql = 'SELECT * FROM `users` WHERE `name` = ? OR `name` = ?';
        $this->assertSame($expectedSql, $builder->toSql());
        $this->assertSame(['Alice', 'Bob'], $builder->getBindings());
    }

    /**
     * Tests limit and offset together.
     */
    public function testLimitAndOffsetTogether(): void
    {
        $builder = $this->connection->table('users')
            ->limit(10)
            ->offset(20);

        $sql = $builder->toSql();
        $this->assertStringContainsString('LIMIT 10', $sql);
        $this->assertStringContainsString('OFFSET 20', $sql);
    }

    /**
     * Tests that update with where correctly applies bindings.
     */
    public function testUpdateWithWhereAppliesBindingsCorrectly(): void
    {
        $builder = $this->connection->table('users');
        $builder->insert(['name' => 'Alice', 'email' => 'alice@test.com', 'age' => 25, 'status' => 'active']);
        $builder->insert(['name' => 'Bob', 'email' => 'bob@test.com', 'age' => 17, 'status' => 'active']);

        $affected = $this->connection->table('users')
            ->where('name', 'Alice')
            ->update(['status' => 'inactive']);

        $this->assertSame(1, $affected);

        $alice = $this->connection->table('users')->where('name', 'Alice')->first();
        $this->assertSame('inactive', $alice['status']);

        $bob = $this->connection->table('users')->where('name', 'Bob')->first();
        $this->assertSame('active', $bob['status']);
    }

    /**
     * Tests delete without where deletes all rows.
     */
    public function testDeleteWithoutWhereDeletesAll(): void
    {
        $builder = $this->connection->table('users');
        $builder->insert(['name' => 'Alice', 'email' => 'a@test.com', 'age' => 25, 'status' => 'active']);
        $builder->insert(['name' => 'Bob', 'email' => 'b@test.com', 'age' => 17, 'status' => 'active']);

        $affected = $this->connection->table('users')->delete();
        $this->assertSame(2, $affected);
        $this->assertSame(0, $this->connection->table('users')->count());
    }

    /**
     * Tests delete non-existent row returns 0 affected.
     */
    public function testDeleteNonExistentRowReturnsZero(): void
    {
        $affected = $this->connection->table('users')
            ->where('name', 'nobody')
            ->delete();

        $this->assertSame(0, $affected);
    }

    /**
     * Tests count preserves column selection state after execution.
     */
    public function testCountPreservesSelectState(): void
    {
        $builder = $this->connection->table('users')
            ->select('id', 'name');

        $count = $builder->count();
        $this->assertSame(0, $count);

        $sql = $builder->toSql();
        $this->assertStringContainsString('SELECT `id`, `name`', $sql);
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