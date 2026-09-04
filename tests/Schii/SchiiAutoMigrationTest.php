<?php

declare(strict_types=1);

namespace Phue\Tests\Schii;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use Phue\Core\Database\Drivers\Abstract\DriverInterface;
use Phue\Core\Database\Drivers\Pdo\MysqlDriver;
use Phue\Core\Database\Drivers\Pdo\PostgresDriver;
use Phue\Core\Database\Drivers\Pdo\SqliteDriver;
use Phue\Schii\Attribute\Field;
use Phue\Schii\Attribute\UI;
use Phue\Schii\Migration\AutoMigrator;
use Phue\Schii\Migration\SchemaToSqlConverter;
use Phue\Schii\Schema\FieldSchema;
use Phue\Schii\Schema\ModelSchema;
use Phue\Schii\Schema\SchemaRegistry;

#[UI(label: 'Dynamic Products')]
class MigratableProduct extends BaseActiveRecord
{
    protected string $table = 'migratable_products';

    #[Field(type: 'text', required: true)]
    public string $name = '';

    #[Field(type: 'number')]
    public float $price = 0.0;
}

#[UI(label: 'Dynamic Products V2')]
class MigratableProductV2 extends BaseActiveRecord
{
    protected string $table = 'migratable_products';

    #[Field(type: 'text', required: true)]
    public string $name = '';

    #[Field(type: 'number')]
    public float $price = 0.0;

    // New field added dynamically
    #[Field(type: 'text')]
    public string $sku = '';
}

#[UI(label: 'Invalid Table Model')]
class InvalidTableProduct extends BaseActiveRecord
{
    // Injection / invalid identifier test case
    protected string $table = 'products; DROP TABLE users;--';

    #[Field(type: 'text')]
    public string $name = '';
}

/**
 * Integration and Unit tests for Schii Auto-Migration system.
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
class SchiiAutoMigrationTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        BaseActiveRecord::setConnection($this->db);
    }

    /**
     * Test full migration lifecycle: Table creation, no-op run, and adding missing columns via ALTER TABLE.
     */
    public function testAutoMigrateCreatesTableAndAltersColumns(): void
    {
        $registry = new SchemaRegistry();
        $migrator = new AutoMigrator($registry, $this->db);

        // 1. Initial Migration (Creates Table)
        $actions = $migrator->migrate(MigratableProduct::class);
        $this->assertCount(1, $actions);
        $this->assertStringContainsString("Created table 'migratable_products'", $actions[0]);

        // 2. Second Run (No Changes)
        $actionsNoOp = $migrator->migrate(MigratableProduct::class);
        $this->assertEmpty($actionsNoOp);

        // 3. Schema V2 Migration (Adds 'sku' Column via ALTER TABLE without trailing semicolon)
        $actionsV2 = $migrator->migrate(MigratableProductV2::class);
        $this->assertCount(1, $actionsV2);
        $this->assertStringContainsString("Added column 'sku'", $actionsV2[0]);
    }

    /**
     * Test that invalid table names trigger an InvalidArgumentException to prevent SQL injection.
     */
    public function testAutoMigrateThrowsExceptionForInvalidTableName(): void
    {
        $registry = new SchemaRegistry();
        $migrator = new AutoMigrator($registry, $this->db);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid database identifier name");

        $migrator->migrate(InvalidTableProduct::class);
    }

    /**
     * Test that all concrete driver implementations return their expected normalized driver name.
     */
    public function testConcreteDriversReturnCorrectName(): void
    {
        $sqlite = new SqliteDriver([]);
        $mysql = new MysqlDriver([]);
        $postgres = new PostgresDriver([]);

        $this->assertEquals('sqlite', $sqlite->getName());
        $this->assertEquals('mysql', $mysql->getName());
        $this->assertEquals('pgsql', $postgres->getName());
    }

    /**
     * Test driver resolution via DriverInterface contract on the AutoMigrator.
     */
    public function testAutoMigratorDetectsDriverViaInterfaceName(): void
    {
        $driverMock = $this->createMock(DriverInterface::class);
        $driverMock->method('getName')->willReturn('mysql');

        $connectionMock = $this->createMock(Connection::class);
        $connectionMock->method('getDriver')->willReturn($driverMock);

        $registry = new SchemaRegistry();
        $migrator = new AutoMigrator($registry, $connectionMock);

        // Access private method getDriverName() using Reflection
        $reflection = new \ReflectionClass($migrator);
        $method = $reflection->getMethod('getDriverName');

        $resolvedName = $method->invoke($migrator);
        $this->assertEquals('mysql', $resolvedName);
    }

    /**
     * Test dialect-specific DDL generation across SQLite, MySQL, and PostgreSQL.
     */
    public function testSchemaToSqlConverterGeneratesCorrectDialectSql(): void
    {
        // Added required 'label' argument (or named parameter label: 'Active')
        $field = new FieldSchema(
            name: 'active',
            type: 'boolean',
            label: 'Active',
            widget: 'checkbox',
            column: true,
            searchable: false,
            filterable: false,
            sortable: false,
            required: true
        );

        $schema = new ModelSchema(
            entity: MigratableProduct::class,
            className: 'users',
            label: 'User',
            icon: 'users',
            layout: 'main',
            description: 'User test model schema',
            fields: [$field],
            permissions: []
        );

        // 1. SQLite Dialect
        $sqliteConverter = new SchemaToSqlConverter('sqlite');
        $sqliteSql = $sqliteConverter->buildCreateTableSql($schema, 'users');
        $this->assertStringContainsString('`id` INTEGER PRIMARY KEY AUTOINCREMENT', $sqliteSql);
        $this->assertStringContainsString('`active` TINYINT(1) NOT NULL', $sqliteSql);

        // 2. MySQL Dialect
        $mysqlConverter = new SchemaToSqlConverter('mysql');
        $mysqlSql = $mysqlConverter->buildCreateTableSql($schema, 'users');
        $this->assertStringContainsString('`id` INT AUTO_INCREMENT PRIMARY KEY', $mysqlSql);
        $this->assertStringContainsString('`active` TINYINT(1) NOT NULL', $mysqlSql);

        // 3. PostgreSQL Dialect
        $pgsqlConverter = new SchemaToSqlConverter('pgsql');
        $pgsqlSql = $pgsqlConverter->buildCreateTableSql($schema, 'users');
        $this->assertStringContainsString('"id" SERIAL PRIMARY KEY', $pgsqlSql);
        $this->assertStringContainsString('"active" BOOLEAN NOT NULL', $pgsqlSql);
    }

    /**
     * Test that a failed ALTER TABLE statement triggers a transaction rollback.
     *
     * Note: Connection proxies driver methods through __call(), so a sub-class
     * overriding __call() is the only way to fault-inject without mocking the
     * (non-existent) Connection method surface.
     */
    public function testAutoMigratorRollsBackWhenColumnAlterFails(): void
    {
        $failDb = new FailOnAlterConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        BaseActiveRecord::setConnection($failDb);

        $registry = new SchemaRegistry();
        $migrator = new AutoMigrator($registry, $failDb);

        // Initial migration succeeds: CREATE TABLE passes through the override.
        $initial = $migrator->migrate(MigratableProduct::class);
        $this->assertCount(1, $initial);

        try {
            $migrator->migrate(MigratableProductV2::class);
            $this->fail('Expected RuntimeException for failing ALTER TABLE');
        } catch (\RuntimeException $e) {
            $this->assertSame('ALTER TABLE failed', $e->getMessage());
        }

        // The transaction must have been rolled back.
        $this->assertFalse($failDb->inTransaction());

        // And the schema must be untouched: 'sku' must NOT exist.
        $stmt = $failDb->query('PRAGMA table_info(migratable_products)');
        $columns = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : (array) $stmt;
        $names = array_map(static fn (array $col): string => $col['name'], $columns);

        $this->assertNotContains('sku', $names);
    }
}

/**
 * Connection sub-class that fails any ALTER TABLE execute() call while passing
 * every other driver call through to the real sqlite driver.
 *
 * @package Phue\Tests\Schii
 */
final class FailOnAlterConnection extends Connection
{
    public function __call(string $method, array $arguments): mixed
    {
        if ($method === 'execute' && str_contains((string) ($arguments[0] ?? ''), 'ALTER TABLE')) {
            throw new \RuntimeException('ALTER TABLE failed');
        }

        return parent::__call($method, $arguments);
    }
}