<?php

declare(strict_types=1);

namespace Phue\Modules\Phii\Generator\Commands;

use Phue\Modules\Schii\Generator\PhiiExportSchema;
use Phue\Modules\Schii\Schema\SchemaRegistry;

/**
 * Trait CommandHelperTrait
 *
 * Provides shared helper methods and CLI flags configuration for Phii generator commands.
 *
 * @package Phue\Modules\Phii\Generator\Commands
 */
trait CommandHelperTrait
{
    /**
     * Resolve model input (either FQCN or slug) to a valid model class string.
     *
     * @param string $input
     *
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    protected function resolveModelClass(string $input): string
    {
        if (class_exists($input)) {
            return $input;
        }

        $studlyName = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $input)));
        $entityNamespace = 'App\\Models';
        if (class_exists(\Phue\Core\Console\PathResolver::class)) {
            $basePath = \Phue\Core\Console\PathResolver::resolveBasePath();
            if (is_file($basePath . '/config/schii.php')) {
                $config = new \Phue\Core\Config($basePath . '/config');
                $entityNamespace = (string) $config->get('schii.entityNamespace', 'App\\Models');
            }
        }
        $fqcn = rtrim($entityNamespace, '\\') . "\\{$studlyName}";

        if (class_exists($fqcn)) {
            return $fqcn;
        }

        throw new \InvalidArgumentException("Could not resolve model class for '{$input}' (tried {$fqcn}).");
    }

    /**
     * Resolve schema from model input and module.
     *
     * @param SchemaRegistry $schemaRegistry
     * @param string         $modelInput
     * @param string         $module
     *
     * @return PhiiExportSchema
     */
    protected function resolveSchema(SchemaRegistry $schemaRegistry, string $modelInput, string $module = 'admin'): PhiiExportSchema
    {
        $modelClass = $this->resolveModelClass($modelInput);
        return $schemaRegistry->toPhiiExportSchema($modelClass, $module);
    }
}