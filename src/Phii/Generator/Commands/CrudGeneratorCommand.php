<?php

declare(strict_types=1);

namespace Phue\Generator\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Generator\CrudGenerator;
use Phue\Schii\Schema\SchemaRegistry;

/**
 * Orchestrator console command for full CRUD generation.
 *
 * @package Phue\Generator\Commands
 */
final class CrudGeneratorCommand implements CommandInterface
{
    use CommandHelperTrait;

    private CrudGenerator $generator;
    private SchemaRegistry $schemaRegistry;

    /**
     * @param CrudGenerator|null  $generator
     * @param SchemaRegistry|null $schemaRegistry
     */
    public function __construct(
        ?CrudGenerator $generator = null,
        ?SchemaRegistry $schemaRegistry = null
    ) {
        $this->generator = $generator ?? new CrudGenerator();
        $this->schemaRegistry = $schemaRegistry ?? new SchemaRegistry(getcwd());
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'generate:crud';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Generates full CRUD (Model, Controller, Migration, Routes, Views) for a Schii schema.';
    }

    /**
     * Execute console command handling model positional argument and flags.
     *
     * @param array<int, string> $args
     * @param mixed              $outputStream
     * @param mixed              $errorStream
     *
     * @return int
     */
    public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        $basePath = getcwd();
        $module = 'admin';
        $modelInput = null;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--output=')) {
                $basePath = substr($arg, 9);
            } elseif (str_starts_with($arg, '--path=')) {
                $basePath = substr($arg, 7);
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
            $schema = $this->resolveSchema($this->schemaRegistry, $modelInput, $module);
            $results = $this->generator->generate($schema, $module, $basePath);

            fwrite($outputStream, "CRUD generation completed:\n");
            foreach ($results as $type => $info) {
                fwrite($outputStream, " - [{$type}]: {$info}\n");
            }
            return CommandInterface::SUCCESS;
        } catch (\Throwable $e) {
            fwrite($errorStream, "Error: " . $e->getMessage() . "\n");
            return CommandInterface::FAILURE;
        }
    }
}