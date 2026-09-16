<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Generator;

use InvalidArgumentException;
use RuntimeException;
use Phue\Modules\Schii\Schema\SchemaRegistry;

/**
 * File generator that exports Schii schemas into standalone PHP return expression files.
 *
 * @package Phue\Modules\Schii\Generator
 */
final class BuildGenerator implements FileGeneratorInterface
{
    private const DEFAULT_OUTPUT_DIR = 'storage/generated/schemas';

    /**
     * BuildGenerator constructor.
     *
     * @param SchemaRegistry $schemaRegistry Registry instance holding model schemas.
     * @param string         $outputBase     Base directory for generated schema files.
     */
    public function __construct(
        private readonly SchemaRegistry $schemaRegistry,
        private readonly string $outputBase = self::DEFAULT_OUTPUT_DIR,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'build';
    }

    /**
     * Returns the target directory where exported schema files are written.
     *
     * @return string
     */
    public function getOutputDir(): string
    {
        return rtrim($this->outputBase, '/\\');
    }

    /**
     * Ensures the output directory exists, creating it recursively if needed.
     *
     * @throws RuntimeException If directory creation fails.
     * @return void
     */
    public function ensureOutputDir(): void
    {
        $dir = $this->getOutputDir();

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Failed to create output directory: "%s".', $dir));
        }
    }

    /**
     * Export a single entity schema by its model FQCN.
     *
     * @param class-string $modelClass e.g. 'App\\Models\\User'
     * @param string       $module     e.g. 'admin'
     *
     * @return string Path to generated file.
     */
    public function exportEntity(
        string $modelClass,
        string $module = 'admin',
    ): string {
        $exportSchema = $this->schemaRegistry->toPhiiExportSchema($modelClass, $module);

        return $this->exportSchema($exportSchema);
    }

    /**
     * Export all registered entity schemas in a given namespace.
     *
     * @param string $entityNamespace e.g. 'App\\Models'
     * @param string $module          Target module name.
     *
     * @return array<string, string> Map of model class -> file path.
     */
    public function exportAll(
        string $entityNamespace,
        string $module = 'admin',
    ): array {
        $manifest = [];
        $classes = $this->schemaRegistry->discoverModelClasses($entityNamespace);

        foreach ($classes as $modelClass) {
            $manifest[$modelClass] = $this->exportEntity($modelClass, $module);
        }

        return $manifest;
    }

    /**
     * Exports a PhiiExportSchema instance into a standalone PHP return file.
     *
     * @param PhiiExportSchema $schema The export schema instance.
     *
     * @return string Path to the generated PHP file.
     */
    public function exportSchema(PhiiExportSchema $schema): string
    {
        $this->ensureOutputDir();

        $fileName = strtolower($schema->entity) . '.php';
        $filePath = $this->getOutputDir() . '/' . $fileName;

        $code = $this->writeSchemaFile($schema);
        file_put_contents($filePath, $code);

        return $filePath;
    }

    /**
     * Writes a PHP file returning a PhiiExportSchema instance.
     *
     * @param PhiiExportSchema $schema
     *
     * @return string
     */
    private function writeSchemaFile(PhiiExportSchema $schema): string
    {
        $data = $schema->toArray();
        $exportedData = var_export($data, true);

        return sprintf(
            <<<'PHP'
<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Generator;

// Auto-generated Phii Export Schema for %s
return PhiiExportSchema::fromArray(%s);

PHP,
            $schema->entity,
            $exportedData
        );
    }
}