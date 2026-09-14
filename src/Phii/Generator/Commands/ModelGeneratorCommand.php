<?php

declare(strict_types=1);

namespace Phue\Generator\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Generator\ModelGenerator;
use Phue\Schii\Schema\SchemaRegistry;

/**
 * Console command for generating model classes from Schii schemas.
 *
 * @package Phue\Generator\Commands
 */
final class ModelGeneratorCommand implements CommandInterface
{
    use CommandHelperTrait;

    private ModelGenerator $generator;
    private SchemaRegistry $schemaRegistry;

    /**
     * @param ModelGenerator|null $generator
     * @param SchemaRegistry|null $schemaRegistry
     */
    public function __construct(
        ?ModelGenerator $generator = null,
        ?SchemaRegistry $schemaRegistry = null
    ) {
        $this->generator = $generator ?? new ModelGenerator();
        $this->schemaRegistry = $schemaRegistry ?? new SchemaRegistry(getcwd());
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'generate:model';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Generates an ActiveRecord or Data Mapper model class from a Schii schema.';
    }

    /**
     * Execute console command handling positional model argument and flags.
     *
     * @param array<int, string> $args
     * @param mixed              $outputStream
     * @param mixed              $errorStream
     *
     * @return int Exit code.
     */
    public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        $outputDir = getcwd();
        $type = 'active-record';
        $module = 'admin';
        $modelInput = null;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--output=')) {
                $outputDir = substr($arg, 9);
            } elseif (str_starts_with($arg, '--type=')) {
                $type = substr($arg, 7);
            } elseif (str_starts_with($arg, '--module=')) {
                $module = substr($arg, 9);
            } elseif (!str_starts_with($arg, '-')) {
                $modelInput ??= $arg;
            }
        }

        if ($modelInput === null) {
            fwrite($errorStream, "Error: Missing positional argument for model name or FQCN.\n");
            return CommandInterface::FAILURE;
        }

        try {
            $modelClass = $this->resolveModelClass($modelInput);
            $schema = $this->schemaRegistry->toPhiiExportSchema($modelClass, $module);

            $generatedPath = $this->generator->generateFromSchema($schema, $type, $outputDir);

            fwrite($outputStream, "Generated model at: {$generatedPath}\n");
            return CommandInterface::SUCCESS;
        } catch (\Throwable $e) {
            fwrite($errorStream, "Error: " . $e->getMessage() . "\n");
            return CommandInterface::FAILURE;
        }
    }
}