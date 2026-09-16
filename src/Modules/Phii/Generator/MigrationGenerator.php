<?php

declare(strict_types=1);

namespace Phue\Modules\Phii\Generator;

use Phue\Modules\Phii\Generator\FileMutation;
use Phue\Modules\Schii\Generator\PhiiExportSchema;
use Phue\Modules\Schii\Schema\ModelSchema;
use Phue\Modules\Schii\Migration\SchemaToSqlConverter;

/**
 * Generates database migration file mutations (up/down SQL and schema blueprint) from Schii export schemas.
 *
 * @package Phue\Modules\Phii\Generator
 */
final class MigrationGenerator
{
    /**
     * Generate a migration file mutation from schema.
     *
     * @param PhiiExportSchema $schema   Export schema definition.
     * @param string           $basePath Output directory path.
     * @param string           $driver   Database driver name ('sqlite', 'mysql', 'pgsql').
     *
     * @return array<int, FileMutation> List of file mutations.
     */
    public function generateFromSchema(
        PhiiExportSchema $schema,
        string $basePath = '',
        string $driver = 'sqlite'
    ): array {
        $templateFile = __DIR__ . '/templates/migration.php';
        $template = file_get_contents($templateFile);
        if ($template === false) {
            throw new \RuntimeException("Failed to load template: {$templateFile}");
        }

        $tableName = $schema->table;
        $timestamp = date('Y_m_d_His');
        $fileName = "{$timestamp}_create_{$tableName}.php";

        $upSql = $this->generateUpSql($schema, $driver);
        $downSql = $this->generateDownSql($schema);

        $columnsCode = $this->buildColumnsCode($schema->fields, $driver);

        $output = str_replace(
            ['{{ TABLE_NAME }}', '{{ COLUMNS }}', '{{ UP_SQL }}', '{{ DOWN_SQL }}'],
            [$tableName, $columnsCode, $upSql, $downSql],
            $template
        );

        $filePath = rtrim($basePath, '/') . "/{$fileName}";

        return [new FileMutation($filePath, $output)];
    }

    /**
     * Generate CREATE TABLE SQL statements reuse SchemaToSqlConverter.
     *
     * @param PhiiExportSchema $schema
     * @param string           $driver
     *
     * @return string
     */
    public function generateUpSql(PhiiExportSchema $schema, string $driver): string
    {
        $converter = new SchemaToSqlConverter($driver);
        $modelSchema = $this->convertToModelSchema($schema);

        return $converter->buildCreateTableSql($modelSchema, $schema->table);
    }

    /**
     * Generate DROP TABLE SQL statement.
     *
     * @param PhiiExportSchema $schema
     *
     * @return string
     */
    public function generateDownSql(PhiiExportSchema $schema): string
    {
        return "DROP TABLE IF EXISTS `{$schema->table}`;";
    }

    /**
     * Convert PhiiExportSchema to ModelSchema compatible with SchemaToSqlConverter.
     *
     * @param PhiiExportSchema $schema
     *
     * @return ModelSchema
     */
    private function convertToModelSchema(PhiiExportSchema $schema): ModelSchema
    {
        $fields = [];
        foreach ($schema->fields as $key => $field) {
            $fields[$key] = method_exists($field, 'toFieldSchema') 
                ? $field->toFieldSchema() 
                : new \Phue\Modules\Schii\Schema\FieldSchema(
                    name: $field->name,
                    type: $field->type,
                    label: $field->label ?? '',
                    widget: $field->widget ?? null,
                    column: $field->column ?? null,
                    required: $field->required ?? false,
                    searchable: $field->searchable ?? false,
                    filterable: $field->filterable ?? false,
                    sortable: $field->sortable ?? false,
                    options: $field->options ?? [],
                    rules: $field->rules ?? []
                );
        }

        return new ModelSchema(
            entity: $schema->entity,
            className: $schema->className,
            label: $schema->label,
            icon: $schema->icon,
            layout: $schema->layout,
            description: $schema->description,
            fields: $fields,
            permissions: $schema->permissions
        );
    }

    /**
     * Builds blueprint column lines for migration file.
     *
     * @param array<int, mixed> $fields
     * @param string            $driver
     *
     * @return string
     */
    private function buildColumnsCode(array $fields, string $driver): string
    {
        $lines = [];

        foreach ($fields as $field) {
            $name = $field->name ?? 'column';
            if ($name === 'id') {
                continue;
            }
            $type = $field->type ?? 'string';
            $lines[] = sprintf("            \$table->%s('%s');", $type, $name);
        }

        return implode("\n", $lines);
    }
}