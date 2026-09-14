<?php

declare(strict_types=1);

namespace Phue\Schii\Migration;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use Phue\Core\Database\SqlIdentifier;
use Phue\Schii\Schema\SchemaRegistry;
use Phue\Schii\Schema\SchemaInspector;
use Phue\Schii\Schema\SchemaSyncEngine;
/**
 * Inspects database tables and auto-migrates missing columns and tables based on Schii Schema.
 */
final class AutoMigrator
{
    private Connection $connection;
    private SchemaToSqlConverter $converter;
    private SchemaSyncEngine $syncEngine;

    /**
     * AutoMigrator constructor.
     *
     * @param Connection           $connection      Active database connection instance.
     * @param SchemaRegistry|null  $schemaRegistry  Optional schema registry instance.
     * @param SchemaInspector|null $schemaInspector Optional database schema inspector instance.
     */
    public function __construct(
        Connection $connection,
        ?SchemaRegistry $schemaRegistry = null,
        ?SchemaInspector $schemaInspector = null
    ) {
        $registry = $schemaRegistry ?? new SchemaRegistry();
        $inspector = $schemaInspector ?? new SchemaInspector($connection);

        $this->syncEngine = new SchemaSyncEngine($registry, $inspector, $connection);
    }

    /**
     * Run auto-migration for a specific ActiveRecord model class using SchemaSyncEngine.
     *
     * @param class-string<BaseActiveRecord> $modelClass Target ActiveRecord model class name.
     *
     * @return array<string> List of executed SQL queries/actions.
     *
     * @throws \InvalidArgumentException If the class does not extend BaseActiveRecord.
     * @throws \Throwable If an error occurs during execution.
     */
    public function migrate(string $modelClass): array
    {
        if (!is_subclass_of($modelClass, BaseActiveRecord::class)) {
            throw new \InvalidArgumentException("Class {$modelClass} must extend BaseActiveRecord.");
        }

        return $this->syncEngine->apply($modelClass, dryRun: false, allowDrops: false);
    }

    /**
     * Check whether a specific table exists in the database.
     *
     * @param string $tableName
     * @return bool
     */
    private function tableExists(string $tableName): bool
    {
        if ($this->isSqlite()) {
            $stmt = $this->connection->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
                [$tableName]
            );
            $results = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : (array) $stmt;
            return !empty($results);
        }

        $stmt = $this->connection->query("SHOW TABLES LIKE ?", [$tableName]);
        $results = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : (array) $stmt;
        return !empty($results);
    }

    /**
     * Retrieve a list of existing column names from a specific table.
     *
     * @param string $tableName
     * @return array<string>
     */
    private function getExistingColumns(string $tableName): array
    {
        $driverName = $this->getDriverName();
        $quotedTable = SqlIdentifier::quote($tableName, $driverName);

        if ($this->isSqlite()) {
            $stmt = $this->connection->query("PRAGMA table_info({$quotedTable})");
            $columns = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : iterator_to_array($stmt);

            return array_map(static fn ($col) => is_object($col) ? $col->name : $col['name'], $columns);
        }

        $stmt = $this->connection->query("DESCRIBE {$quotedTable}");
        $columns = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : iterator_to_array($stmt);

        return array_map(static fn ($col) => is_object($col) ? $col->Field : $col['Field'], $columns);
    }

    /**
     * Helper method to reliably check if the active database driver is SQLite.
     *
     * @return bool
     */
    private function isSqlite(): bool
    {
        return $this->getDriverName() === 'sqlite';
    }

    /**
     * Resolves the lowercased database driver name (e.g., 'sqlite', 'mysql', 'pgsql').
     *
     * Uses a multi-tiered fallback strategy to ensure high compatibility:
     * 1. Direct Interface Call: Invokes `getName()` if supported by the driver.
     * 2. Instance Type Check: Performs explicit `instanceof` checks against core driver classes.
     * 3. String Reflection Fallback: Inspects driver class names for legacy or custom implementations.
     *
     * @return string Normalized driver identifier ('sqlite'|'mysql'|'pgsql').
     */
    private function getDriverName(): string
    {
        $driver = $this->connection->getDriver();

        // 1. Direct method call if interface implementation exists
        if (is_object($driver) && method_exists($driver, 'getName')) {
            return strtolower((string) $driver->getName());
        }

        // 2. Explicit driver class instance check
        if ($driver instanceof \Phue\Core\Database\Drivers\Pdo\SqliteDriver) {
            return 'sqlite';
        }

        if ($driver instanceof \Phue\Core\Database\Drivers\Pdo\MysqlDriver) {
            return 'mysql';
        }

        if ($driver instanceof \Phue\Core\Database\Drivers\Pdo\PostgresDriver) {
            return 'pgsql';
        }

        // 3. Fallback string matching against class name
        $driverClass = is_object($driver) ? get_class($driver) : (string) $driver;
        
        if (stripos($driverClass, 'sqlite') !== false) {
            return 'sqlite';
        }

        if (stripos($driverClass, 'mysql') !== false) {
            return 'mysql';
        }

        if (stripos($driverClass, 'postgres') !== false || stripos($driverClass, 'pgsql') !== false) {
            return 'pgsql';
        }

        return 'sqlite'; // Default safety fallback
    }
}