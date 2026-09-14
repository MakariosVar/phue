<?php

declare(strict_types=1);

namespace Phue\Schii\Schema;

/**
 * Represents a planned set of schema alterations for a database table.
 *
 * @package Phue\Schii\Schema
 */
final readonly class SchemaDiff
{
    /**
     * SchemaDiff constructor.
     *
     * @param string                                                       $table        Target table name.
     * @param array<int, array{op: SchemaOperation, column?: ColumnDefinition, new?: ColumnDefinition}> $operations List of planned operations.
     * @param array<string, ColumnDefinition>                              $baseColumns  Map of existing column definitions.
     */
    public function __construct(
        public string $table,
        public array $operations = [],
        public array $baseColumns = [],
    ) {}

    /**
     * Determine whether the diff contains any operations.
     *
     * @return bool True if empty, false if changes exist.
     */
    public function isEmpty(): bool
    {
        return empty($this->operations);
    }

    /**
     * Check if the diff includes destructive drop column operations.
     *
     * @return bool True if a drop column operation is present.
     */
    public function hasDrops(): bool
    {
        foreach ($this->operations as $operation) {
            if ($operation['op'] === SchemaOperation::DropColumn) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate driver-specific SQL statements for the planned diff.
     *
     * @param string $driver Target database driver ('mysql', 'pgsql', 'sqlite').
     *
     * @return array<int, string> List of executable SQL statements.
     */
    public function toSqls(string $driver): array
    {
        $driver = strtolower($driver);
        $sqls = [];

        if ($driver === 'sqlite' && $this->requiresTableRebuild()) {
            return $this->buildSqliteRebuildSql();
        }

        foreach ($this->operations as $opData) {
            $op = $opData['op'];
            $col = $opData['column'] ?? null;
            $newCol = $opData['new'] ?? null;

            match ($op) {
                SchemaOperation::CreateTable => $sqls[] = $this->buildCreateTableSql($driver, $col),
                SchemaOperation::AddColumn => $sqls[] = $this->buildAddColumnSql($driver, $col),
                SchemaOperation::AlterColumn => $sqls[] = $this->buildAlterColumnSql($driver, $col, $newCol),
                SchemaOperation::DropColumn => $sqls[] = $this->buildDropColumnSql($driver, $col),
            };
        }

        return array_values(array_filter($sqls));
    }

    /**
     * Determines whether operations require an SQLite table rebuild strategy.
     *
     * @return bool
     */
    private function requiresTableRebuild(): bool
    {
        foreach ($this->operations as $opData) {
            if ($opData['op'] === SchemaOperation::AlterColumn || $opData['op'] === SchemaOperation::DropColumn) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build safe SQLite table rebuild SQL statements for ALTER/DROP operations.
     *
     * @return array<int, string>
     */
    private function buildSqliteRebuildSql(): array
    {
        $tempTable = $this->table . '_temp_' . bin2hex(random_bytes(4));
        $columns = $this->baseColumns;

        foreach ($this->operations as $opData) {
            $op = $opData['op'];
            if ($op === SchemaOperation::DropColumn && isset($opData['column'])) {
                unset($columns[$opData['column']->name]);
            } elseif ($op === SchemaOperation::AlterColumn && isset($opData['new'])) {
                $columns[$opData['new']->name] = $opData['new'];
            } elseif ($op === SchemaOperation::AddColumn && isset($opData['column'])) {
                $columns[$opData['column']->name] = $opData['column'];
            }
        }

        $columnDefs = [];
        foreach ($columns as $colDef) {
            $columnDefs[] = $this->formatColumnDefinition('sqlite', $colDef);
        }

        $colNames = array_keys($columns);
        $escapedColNames = array_map(fn($n) => $this->escapeIdentifier('sqlite', $n), $colNames);
        $colList = implode(', ', $escapedColNames);

        return [
            sprintf('CREATE TABLE %s (%s)', $this->escapeIdentifier('sqlite', $tempTable), implode(', ', $columnDefs)),
            sprintf(
                'INSERT INTO %s (%s) SELECT %s FROM %s',
                $this->escapeIdentifier('sqlite', $tempTable),
                $colList,
                $colList,
                $this->escapeIdentifier('sqlite', $this->table)
            ),
            sprintf('DROP TABLE %s', $this->escapeIdentifier('sqlite', $this->table)),
            sprintf('ALTER TABLE %s RENAME TO %s', $this->escapeIdentifier('sqlite', $tempTable), $this->escapeIdentifier('sqlite', $this->table)),
        ];
    }

    /**
     * Format a CREATE TABLE statement.
     *
     * @param string                $driver
     * @param ColumnDefinition|null $col
     *
     * @return string
     */
    private function buildCreateTableSql(string $driver, ?ColumnDefinition $col): string
    {
        if ($col === null) {
            return '';
        }

        return sprintf(
            'CREATE TABLE IF NOT EXISTS %s (%s)',
            $this->escapeIdentifier($driver, $this->table),
            $this->formatColumnDefinition($driver, $col)
        );
    }

    /**
     * Format an ADD COLUMN statement.
     *
     * @param string                $driver
     * @param ColumnDefinition|null $col
     *
     * @return string
     */
    private function buildAddColumnSql(string $driver, ?ColumnDefinition $col): string
    {
        if ($col === null) {
            return '';
        }

        return sprintf(
            'ALTER TABLE %s ADD COLUMN %s',
            $this->escapeIdentifier($driver, $this->table),
            $this->formatColumnDefinition($driver, $col)
        );
    }

    /**
     * Format an ALTER COLUMN statement.
     *
     * @param string                $driver
     * @param ColumnDefinition|null $col
     * @param ColumnDefinition|null $newCol
     *
     * @return string
     */
    private function buildAlterColumnSql(string $driver, ?ColumnDefinition $col, ?ColumnDefinition $newCol): string
    {
        if ($newCol === null) {
            return '';
        }

        $tableName = $this->escapeIdentifier($driver, $this->table);
        $colName = $this->escapeIdentifier($driver, $newCol->name);

        return match ($driver) {
            'mysql' => sprintf(
                'ALTER TABLE %s MODIFY COLUMN %s',
                $tableName,
                $this->formatColumnDefinition($driver, $newCol)
            ),
            'pgsql' => sprintf(
                'ALTER TABLE %s ALTER COLUMN %s TYPE %s',
                $tableName,
                $colName,
                strtoupper($this->mapTypeToSql($driver, $newCol->type))
            ),
            default => sprintf(
                'ALTER TABLE %s MODIFY COLUMN %s',
                $tableName,
                $this->formatColumnDefinition($driver, $newCol)
            ),
        };
    }

    /**
     * Format a DROP COLUMN statement.
     *
     * @param string                $driver
     * @param ColumnDefinition|null $col
     *
     * @return string
     */
    private function buildDropColumnSql(string $driver, ?ColumnDefinition $col): string
    {
        if ($col === null) {
            return '';
        }

        return sprintf(
            'ALTER TABLE %s DROP COLUMN %s',
            $this->escapeIdentifier($driver, $this->table),
            $this->escapeIdentifier($driver, $col->name)
        );
    }

    /**
     * Format a column definition into driver-compatible SQL syntax.
     *
     * @param string           $driver
     * @param ColumnDefinition $col
     *
     * @return string
     */
    private function formatColumnDefinition(string $driver, ColumnDefinition $col): string
    {
        $sqlType = $this->mapTypeToSql($driver, $col->type);
        if ($col->type === 'varchar' && $col->length > 0) {
            $sqlType .= sprintf('(%d)', $col->length);
        }

        $parts = [
            $this->escapeIdentifier($driver, $col->name),
            strtoupper($sqlType),
        ];

        if ($col->primaryKey && $driver === 'sqlite') {
            $parts[] = 'PRIMARY KEY';
            if ($col->autoIncrement) {
                $parts[] = 'AUTOINCREMENT';
            }
        } elseif ($col->primaryKey && $driver === 'mysql') {
            $parts[] = 'PRIMARY KEY';
            if ($col->autoIncrement) {
                $parts[] = 'AUTO_INCREMENT';
            }
        } elseif ($col->autoIncrement && $driver === 'pgsql') {
            $parts[1] = 'SERIAL';
        }

        $parts[] = $col->nullable ? 'NULL' : 'NOT NULL';

        if ($col->default !== null) {
            $defaultVal = is_string($col->default) ? $col->default : var_export($col->default, true);
            $parts[] = sprintf('DEFAULT %s', $defaultVal);
        }

        return implode(' ', $parts);
    }

    /**
     * Map generic column types to SQL dialect-specific types.
     *
     * @param string $driver
     * @param string $type
     *
     * @return string
     */
    private function mapTypeToSql(string $driver, string $type): string
    {
        return match ($driver) {
            'mysql' => match ($type) {
                'int' => 'INT',
                'boolean' => 'TINYINT(1)',
                'date' => 'DATE',
                'datetime' => 'DATETIME',
                'text' => 'LONGTEXT',
                'float' => 'DOUBLE',
                default => 'VARCHAR',
            },
            'pgsql' => match ($type) {
                'int' => 'INTEGER',
                'boolean' => 'BOOLEAN',
                'date' => 'DATE',
                'datetime' => 'TIMESTAMP',
                'text' => 'TEXT',
                'float' => 'DOUBLE PRECISION',
                default => 'VARCHAR',
            },
            'sqlite' => match ($type) {
                'int' => 'INTEGER',
                'boolean' => 'INTEGER',
                'date' => 'TEXT',
                'datetime' => 'TEXT',
                'text' => 'TEXT',
                'float' => 'REAL',
                default => 'TEXT',
            },
            default => 'VARCHAR',
        };
    }

    /**
     * Escape database identifiers safely based on driver.
     *
     * @param string $driver
     * @param string $identifier
     *
     * @return string
     */
    private function escapeIdentifier(string $driver, string $identifier): string
    {
        return match ($driver) {
            'mysql' => '`' . str_replace('`', '``', $identifier) . '`',
            'pgsql' => '"' . str_replace('"', '""', $identifier) . '"',
            'sqlite' => '"' . str_replace('"', '""', $identifier) . '"',
            default => $identifier,
        };
    }
}