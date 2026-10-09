<?php

declare(strict_types=1);

namespace Phue\Tests\Schii;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Attributes\Field;
use Phue\Core\Database\Connection;
use Phue\Core\Database\Drivers\Pdo\MysqlDriver;
use Phue\Core\Database\Drivers\Pdo\PostgresDriver;
use Phue\Core\Database\Drivers\Pdo\SqliteDriver;
use Phue\Modules\Schii\Attribute\UI;
use Phue\Modules\Schii\Migration\AutoMigrator;
use Phue\Modules\Schii\Migration\SchemaToSqlConverter;
use Phue\Modules\Schii\Schema\FieldSchema;
use Phue\Modules\Schii\Schema\ModelSchema;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Tests\Support\UsesIsolatedDatabase;

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
    use UsesIsolatedDatabase;

    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = BaseActiveRecord::getConnection();

        // Drop tables for test isolation
        $this->db->execute('DROP TABLE IF EXISTS migratable_products');
    }

    /**
     * Test full migration lifecycle: Table creation, no-op run, and adding missing columns via ALTER TABLE.
     */
    public function testAutoMigrateCreatesTableAndAltersColumns(): void
    {
        $registry = new SchemaRegistry();
        $migrator = new AutoMigrator($this->db, $registry);

        // 1. Initial Migration (Creates Table + first column, then adds the rest)
        $actions = $migrator->migrate(MigratableProduct::class);
        $this->assertCount(2, $actions);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS "migratable_products"', $actions[0]);
        $this->assertStringContainsString('ADD COLUMN "price"', $actions[1]);

        // The table must be real, not just planned
        $columns = $this->tableColumns('migratable_products');
        $this->assertContains('name', $columns);
        $this->assertContains('price', $columns);

        // 2. Second Run (No Changes)
        $actionsNoOp = $migrator->migrate(MigratableProduct::class);
        $this->assertEmpty($actionsNoOp);

        // 3. Schema V2 Migration (Adds 'sku' Column)
        $actionsV2 = $migrator->migrate(MigratableProductV2::class);
        $this->assertCount(1, $actionsV2);
        $this->assertStringContainsString('ADD COLUMN "sku"', $actionsV2[0]);

        $this->assertContains('sku', $this->tableColumns('migratable_products'));
    }

    /**
     * Collect column names of a table via PRAGMA.
     *
     * @param string $tableName
     *
     * @return array<int, string>
     */
    private function tableColumns(string $tableName): array
    {
        $stmt = $this->db->query("PRAGMA table_info({$tableName})");
        $rows = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : (array) $stmt;

        return array_map(
            static fn (array $col): string => (string) $col['name'],
            $rows
        );
    }

    /**
     * Test that invalid table names trigger an InvalidArgumentException to prevent SQL injection.
     */
    public function testAutoMigrateThrowsExceptionForInvalidTableName(): void
    {
        $registry = new SchemaRegistry();
        $migrator = new AutoMigrator($this->db, $registry);

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
     * Test that a failed ADD COLUMN statement triggers a transaction rollback.
     *
     * SQLite refuses to add a NOT NULL column without a DEFAULT value, so the
     * engine must abort the whole batch, roll back, and leave the table untouched.
     */
    public function testAutoMigratorRollsBackWhenColumnAlterFails(): void
    {
        $migrator = new AutoMigrator($this->db, new SchemaRegistry());

        // Initial migration succeeds
        $initial = $migrator->migrate(MigratableProduct::class);
        $this->assertNotEmpty($initial);

        // Existing rows make ADD COLUMN NOT NULL a real SQLite failure:
        // "Cannot add a NOT NULL column with default value NULL"
        $this->db->query("INSERT INTO migratable_products (name) VALUES ('laptop')");

        $thrown = null;
        try {
            $migrator->migrate(MigratableProductFailV2::class);
        } catch (\RuntimeException $failure) {
            $thrown = $failure;
        }

        $this->assertNotNull($thrown, 'Expected RuntimeException for failing ADD COLUMN');
        $this->assertStringContainsString('Failed to apply schema diff', $thrown->getMessage());

        // Nothing leaked from the failed batch: 'flag' must NOT exist.
        $this->assertNotContains('flag', $this->tableColumns('migratable_products'));
    }
}

#[UI(label: 'Dynamic Products V2 - failing batch')]
class MigratableProductFailV2 extends MigratableProduct
{
    #[Field(type: 'boolean', required: true)]
    public bool $flag = false;
}