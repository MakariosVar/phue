<?php

declare(strict_types=1);

namespace Phue\Generator;

use Phue\Schii\Generator\PhiiExportSchema;

/**
 * Generates ActiveRecord or Data Mapper model classes from Schii schemas or raw field definitions.
 *
 * @package Phue\Generator
 */
final class ModelGenerator
{
    /**
     * Generate a model class file from a PhiiExportSchema instance.
     *
     * @param PhiiExportSchema $schema   Exported schema definition.
     * @param string           $type     Model type ('active-record' or 'data-mapper').
     * @param string           $basePath Base output path for writing the file.
     *
     * @return string Path to the generated model file.
     */
    public function generateFromSchema(
        PhiiExportSchema $schema,
        string $type = 'active-record',
        string $basePath = ''
    ): string {
        $templateFile = $type === 'data-mapper'
            ? __DIR__ . '/templates/model-data-mapper.php'
            : __DIR__ . '/templates/model-active-record.php';

        $template = file_get_contents($templateFile);
        if ($template === false) {
            throw new \RuntimeException("Failed to load template: {$templateFile}");
        }

        $modelNamespace = $schema->namespace;
        $modelName = $schema->entity;
        $tableName = $schema->table;

        $propertiesCode = $this->baseBuildProperties($schema->fields);

        $output = str_replace(
            ['{{ NAMESPACE }}', '{{ MODEL_NAME }}', '{{ TABLE_NAME }}', '{{ PROPERTIES }}'],
            [$modelNamespace, $modelName, $tableName, $propertiesCode],
            $template
        );

        $filePath = rtrim($basePath, '/') . "/{$modelName}.php";
        if ($basePath !== '' && !is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        file_put_contents($filePath, $output);

        return $filePath;
    }

    /**
     * Generate a model class file directly from raw field definitions.
     *
     * @param array<int, mixed> $fields     Raw field definitions.
     * @param string            $modelName  Target model class name.
     * @param string            $tableName  Target database table name.
     * @param string            $namespace  PHP namespace for the generated class.
     * @param string            $basePath   Base output directory path.
     *
     * @return string Path to the generated model file.
     */
    public function generateFromFields(
        array $fields,
        string $modelName,
        string $tableName,
        string $namespace,
        string $basePath
    ): string {
        $templateFile = __DIR__ . '/templates/model-active-record.php';
        $template = file_get_contents($templateFile);
        if ($template === false) {
            throw new \RuntimeException("Failed to load template: {$templateFile}");
        }

        $propertiesCode = $this->baseBuildProperties($fields);

        $output = str_replace(
            ['{{ NAMESPACE }}', '{{ MODEL_NAME }}', '{{ TABLE_NAME }}', '{{ PROPERTIES }}'],
            [$namespace, $modelName, $tableName, $propertiesCode],
            $template
        );

        $filePath = rtrim($basePath, '/') . "/{$modelName}.php";
        if ($basePath !== '' && !is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        file_put_contents($filePath, $output);

        return $filePath;
    }

    /**
     * Build property definitions and Field attributes.
     *
     * @param array<int, mixed> $fields
     *
     * @return string
     */
    private function baseBuildProperties(array $fields): string
    {
        $lines = [];
        foreach ($fields as $field) {
            $name = $field->name ?? 'column';
            $type = $field->type ?? 'string';
            $phpType = match ($type) {
                'int', 'integer' => 'int',
                'bool', 'boolean' => 'bool',
                'float' => 'float',
                default => 'string',
            };

            $lines[] = sprintf(
                "    #[Field(type: '%s', required: %s, searchable: %s, filterable: %s, sortable: %s)]\n    public %s \$%s;",
                $type,
                ($field->required ?? false) ? 'true' : 'false',
                ($field->searchable ?? false) ? 'true' : 'false',
                ($field->filterable ?? false) ? 'true' : 'false',
                ($field->sortable ?? false) ? 'true' : 'false',
                $phpType,
                $name
            );
        }

        return implode("\n\n", $lines);
    }
}