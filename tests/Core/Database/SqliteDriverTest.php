<?php

namespace Phue\Tests\Core\Database;

use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Drivers\Abstract\StatementInterface;
use Phue\Core\Database\Drivers\Pdo\SqliteDriver;

/**
 * Unit tests for SqliteDriver database execution, querying, and transactions.
 *
 * @package Phue\Tests\Core\Database
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
class SqliteDriverTest extends TestCase
{
    private SqliteDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        // Initialize an in-memory SQLite driver instance
        $this->driver = new SqliteDriver(['database' => ':memory:']);
    }

    protected function tearDown(): void
    {
        if ($this->driver->isConnected()) {
            $this->driver->disconnect();
        }
        parent::tearDown();
    }

    /**
     * Tests establishing lazy connection and executing DDL table creation statements.
     */
    public function testConnectsAndExecutesCreateTables(): void
    {
        $this->assertFalse($this->driver->isConnected());

        $affectedRows = $this->driver->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        $this->assertTrue($this->driver->isConnected());
        $this->assertSame(0, $affectedRows);
    }

    /**
     * Tests inserting data, retrieving last insert ID, and querying result sets.
     */
    public function testInsertsAndQueriesData(): void
    {
        $this->driver->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        // 1. Insert Data
        $affectedRows = $this->driver->execute('INSERT INTO users (name) VALUES (?)', ['Makarios']);
        $this->assertSame(1, $affectedRows);

        // 2. Assert Last Insert ID
        $this->assertSame('1', $this->driver->lastInsertId());

        // 3. Query Data via StatementInterface
        $stmt = $this->driver->query('SELECT * FROM users WHERE id = ?', [1]);
        $this->assertInstanceOf(StatementInterface::class, $stmt);

        $row = $stmt->fetch();
        $this->assertIsArray($row);
        $this->assertSame(1, (int) $row['id']);
        $this->assertSame('Makarios', $row['name']);
    }

    /**
     * Tests transaction workflow including rollback and commit behavior.
     */
    public function testTransactionCommitAndRollback(): void
    {
        $this->driver->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        // 1. Test Rollback
        $this->driver->beginTransaction();
        $this->assertTrue($this->driver->inTransaction());

        $this->driver->execute('INSERT INTO users (name) VALUES (?)', ['TemporaryUser']);
        $this->driver->rollBack();

        $this->assertFalse($this->driver->inTransaction());

        $stmt = $this->driver->query('SELECT COUNT(*) as total FROM users');
        $this->assertSame(0, (int) $stmt->fetchColumn());

        // 2. Test Commit
        $this->driver->beginTransaction();
        $this->driver->execute('INSERT INTO users (name) VALUES (?)', ['PermanentUser']);
        $this->driver->commit();

        $stmt = $this->driver->query('SELECT COUNT(*) as total FROM users');
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }
}