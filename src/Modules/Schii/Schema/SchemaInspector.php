<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Schema;

use PDO;
use Phue\Core\Database\Connection;

/**
 * Inspects database tables and normalizes column definitions across supported drivers.
 *
 * Supports SQLite (PRAGMA table_info), MySQL (SHOW FULL COLUMNS), and PostgreSQL
 * (information_schema.columns).
 *
 * @package Phue\Modules\Schii\Schema
 */
final class SchemaInspector
{
    /**
     * SchemaInspector constructor.
     *
     * @param Connection $connection Database connection instance.
     */
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * Inspects a database table and returns a map of column names to ColumnDefinition objects.
     *
     * @param string $table The name of the database table to inspect.
     *
     * @return array<string, ColumnDefinition> Map of column name -> ColumnDefinition
     */
    public function inspect(string $table): array
    {
        $pdo = $this->connection->getPdo();
        $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?? '');

        return match ($driver) {
            'sqlite' => $this->inspectSqlite($pdo, $table),
            'mysql' => $this->inspectMysql($pdo, $table),
            'pgsql' => $this->inspectPgsql($pdo, $table),
            default => [],
        };
    }

    /**
     * Checks whether a database table exists in the underlying connection.
     *
     * @param string $table The table name to check.
     *
     * @return bool True if table exists, false otherwise.
     */
    public function tableExists(string $table): bool
    {
        $pdo = $this->connection->getPdo();
        $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?? '');

        $sql = match ($driver) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type='table' AND name = :table",
            'mysql' => "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table",
            'pgsql' => "SELECT table_name FROM information_schema.tables WHERE table_name = :table",
            default => "SELECT 1 WHERE 1 = 0",
        };

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['table' => $table]);

        return $stmt->fetch() !== false;
    }

    /**
     * Inspects table structure using SQLite PRAGMA table_info.
     *
     * @param PDO    $pdo   Active PDO instance.
     * @param string $table Target table name.
     *
     * @return array<string, ColumnDefinition>
     */
    private function inspectSqlite(PDO $pdo, string $table): array
    {
        // 1. Fetch indexed columns via PRAGMA index_list & PRAGMA index_info
        $indexedColumns = [];
        $indexListStmt = $pdo->prepare(sprintf('PRAGMA index_list(%s)', $pdo->quote($table)));
        $indexListStmt->execute();
        $indexes = $indexListStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($indexes as $index) {
            $indexName = (string) $index['name'];
            $indexInfoStmt = $pdo->prepare(sprintf('PRAGMA index_info(%s)', $pdo->quote($indexName)));
            $indexInfoStmt->execute();
            $indexColumns = $indexInfoStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($indexColumns as $col) {
                if (isset($col['name'])) {
                    $indexedColumns[(string) $col['name']] = true;
                }
            }
        }

        // 2. Fetch table columns information
        $stmt = $pdo->prepare(sprintf('PRAGMA table_info(%s)', $pdo->quote($table)));
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $columns = [];
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $rawType = strtoupper((string) $row['type']);
            $type = $this->normalizeType($rawType);

            $columns[$name] = new ColumnDefinition(
                name: $name,
                type: $type,
                nullable: (int) $row['notnull'] === 0,
                default: $row['dflt_value'],
                primaryKey: (int) $row['pk'] > 0,
                autoIncrement: str_contains($rawType, 'INT') && (int) $row['pk'] > 0,
                indexed: isset($indexedColumns[$name]),
                length: $this->extractLength($rawType),
            );
        }

        return $columns;
    }

    /**
     * Inspects table structure using MySQL SHOW FULL COLUMNS.
     *
     * @param PDO    $pdo   Active PDO instance.
     * @param string $table Target table name.
     *
     * @return array<string, ColumnDefinition>
     */
    private function inspectMysql(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(sprintf('SHOW FULL COLUMNS FROM `%s`', str_replace('`', '``', $table)));
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $columns = [];
        foreach ($rows as $row) {
            $name = (string) $row['Field'];
            $rawType = strtoupper((string) $row['Type']);
            $type = $this->normalizeType($rawType);

            $columns[$name] = new ColumnDefinition(
                name: $name,
                type: $type,
                nullable: strtoupper((string) $row['Null']) === 'YES',
                default: $row['Default'],
                primaryKey: str_contains((string) $row['Key'], 'PRI'),
                autoIncrement: str_contains((string) $row['Extra'], 'auto_increment'),
                indexed: str_contains((string) $row['Key'], 'MUL') || str_contains((string) $row['Key'], 'UNI'),
                length: $this->extractLength($rawType),
            );
        }

        return $columns;
    }

    /**
     * Inspects table structure using PostgreSQL information_schema and pg_indexes.
     *
     * @param PDO    $pdo   Active PDO instance.
     * @param string $table Target table name.
     *
     * @return array<string, ColumnDefinition>
     */
    private function inspectPgsql(PDO $pdo, string $table): array
    {
        // 1. Fetch primary key columns
        $pkSql = "
            SELECT kcu.column_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name
             AND tc.table_schema = kcu.table_schema
            WHERE tc.constraint_type = 'PRIMARY KEY'
              AND tc.table_name = :table
        ";
        $pkStmt = $pdo->prepare($pkSql);
        $pkStmt->execute(['table' => $table]);
        $primaryKeys = array_flip($pkStmt->fetchAll(PDO::FETCH_COLUMN));

        // 2. Fetch indexed columns from pg_indexes
        $indexSql = "
            SELECT indexdef 
            FROM pg_indexes 
            WHERE tablename = :table
        ";
        $indexStmt = $pdo->prepare($indexSql);
        $indexStmt->execute(['table' => $table]);
        $indexDefs = $indexStmt->fetchAll(PDO::FETCH_COLUMN);

        $indexedColumns = [];
        foreach ($indexDefs as $def) {
            // Extract column names inside index definition parenthesis (e.g., CREATE INDEX ... ON table (col1, col2))
            if (preg_match('/\(([^)]+)\)/', $def, $matches)) {
                $cols = explode(',', $matches[1]);
                foreach ($cols as $col) {
                    $colName = trim($col, ' "\'');
                    $indexedColumns[$colName] = true;
                }
            }
        }

        // 3. Fetch column definitions
        $sql = "
            SELECT 
                column_name, 
                data_type, 
                character_maximum_length, 
                is_nullable, 
                column_default
            FROM information_schema.columns
            WHERE table_name = :table
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['table' => $table]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $columns = [];
        foreach ($rows as $row) {
            $name = (string) $row['column_name'];
            $rawType = strtoupper((string) $row['data_type']);
            $type = $this->normalizeType($rawType);

            $columns[$name] = new ColumnDefinition(
                name: $name,
                type: $type,
                nullable: strtoupper((string) $row['is_nullable']) === 'YES',
                default: $row['column_default'],
                primaryKey: isset($primaryKeys[$name]),
                autoIncrement: str_starts_with((string) $row['column_default'], 'nextval'),
                indexed: isset($indexedColumns[$name]) || isset($primaryKeys[$name]),
                length: (int) ($row['character_maximum_length'] ?? 255),
            );
        }

        return $columns;
    }

    /**
     * Normalizes raw database data types into standard Schii type identifiers.
     *
     * @param string $rawType Raw data type string from the database driver.
     *
     * @return string Normalized type ('int', 'boolean', 'date', 'datetime', 'text', 'float', 'varchar').
     */
    private function normalizeType(string $rawType): string
    {
        $upper = strtoupper($rawType);

        if (str_contains($upper, 'INT')) {
            return 'int';
        }
        if (str_contains($upper, 'BOOL')) {
            return 'boolean';
        }
        if (str_contains($upper, 'DATE') && !str_contains($upper, 'TIME')) {
            return 'date';
        }
        if (str_contains($upper, 'TIMESTAMP') || str_contains($upper, 'DATETIME')) {
            return 'datetime';
        }
        if (str_contains($upper, 'TEXT') || str_contains($upper, 'LONGTEXT') || str_contains($upper, 'MEDIUMTEXT')) {
            return 'text';
        }
        if (str_contains($upper, 'FLOAT') || str_contains($upper, 'DOUBLE') || str_contains($upper, 'DECIMAL') || str_contains($upper, 'NUMERIC') || str_contains($upper, 'REAL')) {
            return 'float';
        }

        return 'varchar';
    }

    /**
     * Extracts character or numeric length limits from raw database type strings.
     *
     * @param string $rawType Raw data type string.
     *
     * @return int Extracted length or default 255.
     */
    private function extractLength(string $rawType): int
    {
        if (preg_match('/\((\d+)\)/', $rawType, $matches)) {
            return (int) $matches[1];
        }

        return 255;
    }
}