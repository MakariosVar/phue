<?php

declare(strict_types=1);

namespace Phue\Schii\Migration;

use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use Phue\Core\Database\SqlIdentifier;
use Phue\Schii\Schema\SchemaRegistry;

/**
 * Inspects database tables and auto-migrates missing columns and tables based on Schii Schema.
 */
final class AutoMigrator
{
    private SchemaToSqlConverter $converter;

    public function __construct(
        private readonly SchemaRegistry $schemaRegistry,
        private readonly Connection $connection
    ) {
        $this->converter = new SchemaToSqlConverter($this->getDriverName());
    }

    /**
     * Run auto-migration for a specific ActiveRecord model class wrapped in a database transaction.
     *
     * @param class-string<BaseActiveRecord> $modelClass
     * @return array<string> List of executed SQL queries/actions
     * @throws \InvalidArgumentException If the class does not extend BaseActiveRecord or contains invalid identifiers.
     * @throws \Throwable If an error occurs during query execution.
     */
    public function migrate(string $modelClass): array
    {
        if (!is_subclass_of($modelClass, BaseActiveRecord::class)) {
            throw new \InvalidArgumentException("Class {$modelClass} must extend BaseActiveRecord.");
        }

        $schema = $this->schemaRegistry->get($modelClass);
        
        /** @var BaseActiveRecord $instance */
        $instance = new $modelClass();
        $tableName = SqlIdentifier::validate($instance->getTable());
        $driverName = $this->getDriverName();

        $executedActions = [];

        // Begin transaction wrapping
        $this->connection->beginTransaction();

        try {
            // 1. Check if the target table exists in the database
            if (!$this->tableExists($tableName)) {
                $createSql = $this->converter->buildCreateTableSql($schema, $tableName);
                $this->connection->execute($createSql);
                $executedActions[] = "Created table '{$tableName}'";

                $this->connection->commit();
                return $executedActions;
            }

            // 2. Table exists: Inspect existing columns and ALTER table if any schema field is missing
            $existingColumns = $this->getExistingColumns($tableName);

            foreach ($schema->fields as $field) {
                $fieldName = SqlIdentifier::validate($field->name);

                // Skip primary key column 'id' as it should already exist in the table
                if ($fieldName === 'id') {
                    continue;
                }

                if (!in_array($fieldName, $existingColumns, true)) {
                    $columnSql = $this->converter->buildColumnSql($field);
                    $quotedTable = SqlIdentifier::quote($tableName, $driverName);
                    
                    // Fixed: Removed trailing semicolon at the end of the query string
                    $alterSql = "ALTER TABLE {$quotedTable} ADD COLUMN {$columnSql}";
                    
                    $this->connection->execute($alterSql);
                    $executedActions[] = "Added column '{$fieldName}' to '{$tableName}'";
                }
            }

            $this->connection->commit();
            return $executedActions;
        } catch (\Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $e;
        }
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