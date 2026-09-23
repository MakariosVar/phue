<?php

declare(strict_types=1);

namespace Phue\Modules\Phii\Generator;

use Phue\Core\Database\SqlIdentifier;
use Phue\Modules\Phii\Generator\FileMutation;
use Phue\Modules\Schii\Generator\PhiiExportSchema;
use Phue\Modules\Schii\Migration\SchemaToSqlConverter;
use Phue\Modules\Schii\Schema\FieldSchema;
use Phue\Modules\Schii\Schema\ModelSchema;

/**
 * Generates database migration file mutations from Schii export schemas.
 *
 * Filenames follow the framework's migration convention (`m{stamp}_{order}_...`),
 * identical to the `bin/phue make:migration` output, so the generated class name
 * round-trips through Migrator::classNameFromFile().
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
        string $basePath = 'database/migrations',
        string $driver = 'sqlite'
    ): array {
        $templateFile = __DIR__ . '/templates/migration.php';
        $template = file_get_contents($templateFile);
        if ($template === false) {
            throw new \RuntimeException("Failed to load template: {$templateFile}");
        }

        $tableName = $schema->table;

        // Same-second uniqueness via microsecond order suffix, kept lexicographically sortable.
        $stamp = date('Y_m_d_His');
        $order = (int) (microtime(true) * 1_000_000) % 1_000_000;
        $baseName = sprintf('m%s_%06d_create_%s', $stamp, $order, $tableName);
        $fileName = $baseName . '.php';
        $className = implode('', array_map('ucfirst', explode('_', $baseName)));

        $upSql = $this->generateUpSql($schema, $driver);
        $downSql = $this->generateDownSql($schema, $driver);

        // SQL is embedded inside a double-quoted PHP string literal in the template,
        // so any quote, backslash or dollar sign must be escaped to keep the artifact
        // parseable regardless of driver (pgsql/mysql quote identifiers with ").
        $asPhpString = static fn (string $sql): string => str_replace(
            ['\\', '$', '"'],
            ['\\\\', '\\$', '\\"'],
            $sql
        );

        $output = str_replace(
            ['{{ TABLE_NAME }}', '{{ CLASS_NAME }}', '{{ UP_SQL }}', '{{ DOWN_SQL }}'],
            [$tableName, $className, $asPhpString($upSql), $asPhpString($downSql)],
            $template
        );

        $filePath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;

        return [new FileMutation($filePath, $output)];
    }

    /**
     * Generate the CREATE TABLE SQL statement reusing SchemaToSqlConverter.
     *
     * @param PhiiExportSchema $schema Export schema definition.
     * @param string           $driver Database driver name.
     *
     * @return string Single CREATE TABLE statement.
     */
    public function generateUpSql(PhiiExportSchema $schema, string $driver): string
    {
        $converter = new SchemaToSqlConverter($driver);
        $modelSchema = $this->convertToModelSchema($schema);

        return $converter->buildCreateTableSql($modelSchema, $schema->table);
    }

    /**
     * Generate the DROP TABLE SQL statement.
     *
     * @param PhiiExportSchema $schema Export schema definition.
     *
     * @return string Single DROP TABLE statement.
     */
    public function generateDownSql(PhiiExportSchema $schema, string $driver = 'sqlite'): string
    {
        $quotedTable = SqlIdentifier::quote($schema->table, $driver);

        return "DROP TABLE IF EXISTS {$quotedTable};";
    }

    /**
     * Convert PhiiExportSchema to ModelSchema compatible with SchemaToSqlConverter.
     *
     * @param PhiiExportSchema $schema Export schema definition.
     *
     * @return ModelSchema Schema understood by the SQL converter.
     */
    private function convertToModelSchema(PhiiExportSchema $schema): ModelSchema
    {
        $fields = [];

        foreach ($schema->fields as $key => $field) {
            $fields[$key] = method_exists($field, 'toFieldSchema')
                ? $field->toFieldSchema()
                : new FieldSchema(
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
                    rules: $field->rules ?? [],
                    writable: $field->writable ?? true,
                    readonly: $field->readonly ?? false,
                    primaryKey: $field->primaryKey ?? false,
                    indexed: $field->indexed ?? false,
                    length: $field->length ?? 0,
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
}