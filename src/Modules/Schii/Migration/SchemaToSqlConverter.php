<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Migration;

use Phue\Core\Database\SqlIdentifier;
use Phue\Modules\Schii\Schema\ModelSchema;
use Phue\Modules\Schii\Schema\FieldSchema;

/**
 * Converts Schii ModelSchema definitions into database-specific SQL DDL statements.
 */
final class SchemaToSqlConverter
{
    private string $driverName;

    public function __construct(string $driverName = 'sqlite')
    {
        $this->driverName = strtolower($driverName);
    }

    /**
     * Generate CREATE TABLE SQL query tailored to the configured database dialect.
     */
    public function buildCreateTableSql(ModelSchema $schema, string $tableName): string
    {
        $quotedTableName = SqlIdentifier::quote($tableName, $this->driverName);
        $pkDefinition = $this->getPrimaryKeyDefinition();

        $columns = [
            $pkDefinition,
        ];

        foreach ($schema->fields as $field) {
            // Skip 'id' field as primary key is already defined above
            if ($field->name === 'id') {
                continue;
            }
            $columns[] = $this->buildColumnSql($field);
        }

        $columnsSql = implode(",\n    ", $columns);

        return "CREATE TABLE IF NOT EXISTS {$quotedTableName} (\n    {$columnsSql}\n);";
    }

    /**
     * Generate SQL column definition clause per driver dialect.
     */
    public function buildColumnSql(FieldSchema $field): string
    {
        $quotedFieldName = SqlIdentifier::quote($field->name, $this->driverName);
        $type = $this->resolveSqlType($field->type);
        $nullability = $field->required ? 'NOT NULL' : 'NULL';

        return "{$quotedFieldName} {$type} {$nullability}";
    }

    /**
     * Resolves dialect-specific Primary Key SQL clause.
     */
    private function getPrimaryKeyDefinition(): string
    {
        $quotedId = SqlIdentifier::quote('id', $this->driverName);

        return match ($this->driverName) {
            'mysql' => "{$quotedId} INT AUTO_INCREMENT PRIMARY KEY",
            'pgsql', 'postgres', 'postgresql' => "{$quotedId} SERIAL PRIMARY KEY",
            default => "{$quotedId} INTEGER PRIMARY KEY AUTOINCREMENT", // SQLite
        };
    }

    /**
     * Maps Schii abstract field types to dialect-specific SQL column types.
     */
    private function resolveSqlType(string $schiiType): string
    {
        $isPostgres = in_array($this->driverName, ['pgsql', 'postgres', 'postgresql'], true);

        $typeMap = [
            'text'     => 'VARCHAR(255)',
            'textarea' => 'TEXT',
            'number'   => 'REAL',
            'float'    => 'REAL',
            'integer'  => 'INTEGER',
            'int'      => 'INTEGER',
            'boolean'  => $isPostgres ? 'BOOLEAN' : 'TINYINT(1)',
            'select'   => 'VARCHAR(100)',
            'date'     => 'DATE',
            'datetime' => 'DATETIME',
        ];

        return $typeMap[$schiiType] ?? 'VARCHAR(255)';
    }
}