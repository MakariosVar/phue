<?php

declare(strict_types=1);

namespace Phue\Core\Database;

/**
 * Migration Engine Manager.
 *
 * Scans, tracks, executes (up), and rolls back (down) explicit database migrations inside transactions.
 *
 * @package Phue\Core\Database
 */
final class Migrator
{
    private string $table = 'migrations';

    /**
     * @param Connection $connection Database connection instance.
     * @param string $migrationPath Absolute path to the directory containing migration files.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $migrationPath
    ) {
        $this->ensureMigrationTableExists();
    }

    /**
     * Executes all pending database migrations.
     *
     * @return array<string> List of applied migration names.
     */
    public function run(): array
    {
        $ran = $this->getExecutedMigrations();
        $files = $this->getMigrationFiles();
        $pending = array_diff($files, $ran);

        if (empty($pending)) {
            return [];
        }

        $batch = $this->getNextBatchNumber();
        $applied = [];

        foreach ($pending as $migrationName) {
            $instance = $this->resolveInstance($migrationName);

            $this->connection->beginTransaction();
            try {
                $instance->up();
                $this->logMigration($migrationName, $batch);
                $this->connection->commit();

                $applied[] = $migrationName;
            } catch (\Throwable $e) {
                if ($this->connection->inTransaction()) {
                    $this->connection->rollBack();
                }
                throw new \RuntimeException(
                    "Migration failed on [{$migrationName}]: " . $e->getMessage(),
                    (int) $e->getCode(),
                    $e
                );
            }
        }

        return $applied;
    }

    /**
     * Rolls back the last batch of executed migrations.
     *
     * @return array<string> List of rolled-back migration names.
     */
    public function rollback(): array
    {
        $lastBatch = $this->getLastBatchNumber();
        if ($lastBatch === 0) {
            return [];
        }

        $migrations = $this->getMigrationsForBatch($lastBatch);
        $rolledBack = [];

        foreach ($migrations as $migrationName) {
            $instance = $this->resolveInstance($migrationName);

            $this->connection->beginTransaction();
            try {
                $instance->down();
                $this->removeMigrationRecord($migrationName);
                $this->connection->commit();

                $rolledBack[] = $migrationName;
            } catch (\Throwable $e) {
                if ($this->connection->inTransaction()) {
                    $this->connection->rollBack();
                }
                throw new \RuntimeException(
                    "Rollback failed on [{$migrationName}]: " . $e->getMessage(),
                    (int) $e->getCode(),
                    $e
                );
            }
        }

        return $rolledBack;
    }

    /**
     * Ensures the internal migrations tracking table exists.
     *
     * @return void
     */
    private function ensureMigrationTableExists(): void
    {
        $driver = $this->getDriverName();
        $quotedTable = SqlIdentifier::quote($this->table, $driver);

        $idType = match ($driver) {
            'pgsql' => 'SERIAL PRIMARY KEY',
            'mysql' => 'INT AUTO_INCREMENT PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };

        $sql = "CREATE TABLE IF NOT EXISTS {$quotedTable} (
            `id` {$idType},
            `migration` VARCHAR(255) NOT NULL,
            `batch` INT NOT NULL,
            `executed_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        )";

        $this->connection->execute($sql);
    }

    /**
     * Resolves and instantiates a migration class from its file name.
     *
     * @param string $migrationName Target migration file base name.
     * @return Migration Initialized migration instance.
     */
    private function resolveInstance(string $migrationName): Migration
    {
        $filePath = rtrim($this->migrationPath, '/\\') . DIRECTORY_SEPARATOR . $migrationName . '.php';

        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Migration file not found: [{$filePath}]");
        }

        require_once $filePath;

        $className = $this->classNameFromFile($migrationName);

        if (!class_exists($className)) {
            throw new \RuntimeException("Migration class [{$className}] not found in [{$filePath}]");
        }

        $instance = new $className($this->connection);

        if (!$instance instanceof Migration) {
            throw new \RuntimeException("Class [{$className}] must extend " . Migration::class);
        }

        return $instance;
    }

    /**
     * Converts a migration filename to a valid PHP Class Name.
     * Example: m2026_09_03_000000_create_users_table -> M2026_09_03_000000_Create_Users_Table
     *
     * @param string $filename Migration file identifier.
     * @return string Generated class name.
     */
    private function classNameFromFile(string $filename): string
    {
        return implode('', array_map('ucfirst', explode('_', $filename)));
    }

    /**
     * Scans the migration path for valid .php migration files.
     *
     * @return array<string> Sorted array of migration file identifiers.
     */
    private function getMigrationFiles(): array
    {
        if (!is_dir($this->migrationPath)) {
            return [];
        }

        $files = glob(rtrim($this->migrationPath, '/\\') . '/*.php') ?: [];
        $migrationNames = [];

        foreach ($files as $file) {
            $migrationNames[] = pathinfo($file, PATHINFO_FILENAME);
        }

        sort($migrationNames);
        return $migrationNames;
    }

    /**
     * Retrieves all migration names recorded as executed.
     *
     * @return array<string> Array of executed migration names.
     */
    private function getExecutedMigrations(): array
    {
        $quotedTable = SqlIdentifier::quote($this->table, $this->getDriverName());
        $stmt = $this->connection->query("SELECT `migration` FROM {$quotedTable} ORDER BY `id` ASC");
        
        $rows = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : (array) $stmt;

        return array_map(static fn ($row) => is_object($row) ? $row->migration : $row['migration'], $rows);
    }

    /**
     * Retrieves executed migration names for a specific batch, sorted descending for rollback.
     *
     * @param int $batch Target batch number.
     * @return array<string> Array of migration names.
     */
    private function getMigrationsForBatch(int $batch): array
    {
        $quotedTable = SqlIdentifier::quote($this->table, $this->getDriverName());
        $stmt = $this->connection->query("SELECT `migration` FROM {$quotedTable} WHERE `batch` = ? ORDER BY `id` DESC", [$batch]);

        $rows = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : (array) $stmt;

        return array_map(static fn ($row) => is_object($row) ? $row->migration : $row['migration'], $rows);
    }

    /**
     * Calculates the next batch execution number.
     *
     * @return int Next batch number.
     */
    private function getNextBatchNumber(): int
    {
        return $this->getLastBatchNumber() + 1;
    }

    /**
     * Retrieves the current highest batch number from database.
     *
     * @return int Highest batch number.
     */
    private function getLastBatchNumber(): int
    {
        $quotedTable = SqlIdentifier::quote($this->table, $this->getDriverName());
        $stmt = $this->connection->query("SELECT MAX(`batch`) as max_batch FROM {$quotedTable}");

        $rows = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : (array) $stmt;
        if (empty($rows)) {
            return 0;
        }

        $first = $rows[0];
        $val = is_object($first) ? $first->max_batch : $first['max_batch'];

        return $val !== null ? (int) $val : 0;
    }

    /**
     * Records a migration execution entry in the database.
     *
     * @param string $migration Migration name.
     * @param int $batch Batch number.
     * @return void
     */
    private function logMigration(string $migration, int $batch): void
    {
        $quotedTable = SqlIdentifier::quote($this->table, $this->getDriverName());
        $this->connection->execute(
            "INSERT INTO {$quotedTable} (`migration`, `batch`) VALUES (?, ?)",
            [$migration, $batch]
        );
    }

    /**
     * Removes a migration execution entry during rollback.
     *
     * @param string $migration Migration name.
     * @return void
     */
    private function removeMigrationRecord(string $migration): void
    {
        $quotedTable = SqlIdentifier::quote($this->table, $this->getDriverName());
        $this->connection->execute(
            "DELETE FROM {$quotedTable} WHERE `migration` = ?",
            [$migration]
        );
    }

    /**
     * Resolves lowercased driver name.
     *
     * @return string
     */
    private function getDriverName(): string
    {
        $driver = $this->connection->getDriver();

        if (is_object($driver) && method_exists($driver, 'getName')) {
            return strtolower((string) $driver->getName());
        }

        return 'sqlite';
    }
}