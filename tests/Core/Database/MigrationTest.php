<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Database;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Connection;
use Phue\Core\Database\Migration;
use Phue\Core\Database\Drivers\Pdo\SqliteDriver;

/**
 * Integration & Unit tests for the Migration base class helper methods.
 *
 * These helpers (execute / dropTable / addColumn / getDriverName) are the
 * public API that every explicit migration author relies on, yet previously
 * only `execute()` was exercised indirectly through the Migrator.
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class MigrationTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new Connection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    public function testExecuteRunsRawSqlOnActiveConnection(): void
    {
        $migration = new ExposedMigration($this->db);

        $migration->runExecute("CREATE TABLE settings (id INTEGER PRIMARY KEY, key TEXT NOT NULL)");
        $stmt = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", ['settings']);
        $results = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : (array) $stmt;

        $this->assertCount(1, $results);
    }

    public function testExecuteBindsParameters(): void
    {
        $migration = new ExposedMigration($this->db);
        $migration->runExecute("CREATE TABLE kv (id INTEGER PRIMARY KEY, k TEXT, v TEXT)");
        $migration->runExecute("INSERT INTO kv (k, v) VALUES (?, ?)", ['theme', 'dark']);

        $stmt = $this->db->query("SELECT v FROM kv WHERE k = ?", ['theme']);
        $rows = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : (array) $stmt;

        $this->assertSame('dark', $rows[0]['v']);
    }

    public function testDropTableRemovesExistingTable(): void
    {
        $migration = new ExposedMigration($this->db);
        $migration->runExecute("CREATE TABLE doomed (id INTEGER PRIMARY KEY)");

        $migration->runDropTable('doomed');

        $stmt = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", ['doomed']);
        $results = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : (array) $stmt;
        $this->assertEmpty($results);
    }

    public function testDropTableIsIdempotentWhenTableAbsent(): void
    {
        $migration = new ExposedMigration($this->db);

        $migration->runDropTable('never_existed');

        $this->addToAssertionCount(1);
    }

    public function testAddColumnAddsColumnWithDefinitionToExistingTable(): void
    {
        $migration = new ExposedMigration($this->db);
        $migration->runExecute("CREATE TABLE users (id INTEGER PRIMARY KEY)");

        $migration->runAddColumn('users', 'email', 'VARCHAR(255)');

        $stmt = $this->db->query('PRAGMA table_info(users)');
        $columns = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : (array) $stmt;
        $names = array_map(static fn (array $col): string => $col['name'], $columns);

        $this->assertContains('email', $names);
    }

    public function testGetDriverNameResolvesConcreteDriverName(): void
    {
        $migration = new ExposedMigration($this->db);

        $this->assertSame('sqlite', $migration->runGetDriverName());
    }

    public function testGetDriverNameNormalizesViaDriverGetName(): void
    {
        $driver = $this->createMock(SqliteDriver::class);
        $driver->method('getName')->willReturn('sqlIte');

        $connectionMock = $this->createMock(Connection::class);
        $connectionMock->method('getDriver')->willReturn($driver);

        $migration = new ExposedMigration($connectionMock);

        $this->assertSame('sqlite', $migration->runGetDriverName());
    }
}

/**
 * Concrete Migration subclass exposing the protected helper methods
 * so the base-class contract can be asserted directly.
 *
 * @package Phue\Tests\Core\Database
 */
final class ExposedMigration extends Migration
{
    public function up(): void
    {
    }

    public function down(): void
    {
    }

    public function runExecute(string $sql, array $params = []): mixed
    {
        return $this->execute($sql, $params);
    }

    public function runDropTable(string $tableName): void
    {
        $this->dropTable($tableName);
    }

    public function runAddColumn(string $tableName, string $columnName, string $definition): void
    {
        $this->addColumn($tableName, $columnName, $definition);
    }

    public function runGetDriverName(): string
    {
        return $this->getDriverName();
    }
}