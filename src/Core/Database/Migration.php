<?php

declare(strict_types=1);

namespace Phue\Core\Database;

/**
 * Abstract Base Class for Explicit Database Migrations.
 *
 * Provides schema manipulation helper methods and enforces up/down migration logic.
 *
 * @package Phue\Core\Database
 */
abstract class Migration
{
    /**
     * @param Connection $connection Database connection instance.
     */
    public function __construct(
        protected readonly Connection $connection
    ) {}

    /**
     * Executes schema changes applied by the migration.
     *
     * @return void
     */
    abstract public function up(): void;

    /**
     * Reverts schema changes applied by the migration.
     *
     * @return void
     */
    abstract public function down(): void;

    /**
     * Executes a raw SQL query directly on the active connection.
     *
     * @param string $sql The SQL query string.
     * @param array<int|string, mixed> $params Query binding parameters.
     * @return mixed Query execution result.
     */
    protected function execute(string $sql, array $params = []): mixed
    {
        return $this->connection->execute($sql, $params);
    }

    /**
     * Drops a table if it exists in the database.
     *
     * @param string $tableName Name of the target table.
     * @return void
     */
    protected function dropTable(string $tableName): void
    {
        $quotedTable = SqlIdentifier::quote($tableName, $this->getDriverName());
        $this->connection->execute("DROP TABLE IF EXISTS {$quotedTable}");
    }

    /**
     * Adds a raw column definition to an existing table.
     *
     * @param string $tableName Name of the target table.
     * @param string $columnName Name of the new column.
     * @param string $definition SQL column definition (e.g., 'VARCHAR(255) NOT NULL').
     * @return void
     */
    protected function addColumn(string $tableName, string $columnName, string $definition): void
    {
        $driver = $this->getDriverName();
        $quotedTable = SqlIdentifier::quote($tableName, $driver);
        $quotedColumn = SqlIdentifier::quote($columnName, $driver);

        $this->connection->execute("ALTER TABLE {$quotedTable} ADD COLUMN {$quotedColumn} {$definition}");
    }

    /**
     * Resolves normalized active database driver name.
     *
     * @return string Normalized driver identifier ('sqlite'|'mysql'|'pgsql').
     */
    protected function getDriverName(): string
    {
        $driver = $this->connection->getDriver();

        if (is_object($driver) && method_exists($driver, 'getName')) {
            return strtolower((string) $driver->getName());
        }

        return 'sqlite';
    }
}