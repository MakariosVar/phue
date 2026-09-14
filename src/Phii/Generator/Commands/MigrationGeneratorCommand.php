<?php

declare(strict_types=1);

namespace Phue\Generator\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Generator\MigrationGenerator;
use Phue\Schii\Schema\SchemaRegistry;

/**
 * Console command for generating database migration files from schemas.
 *
 * @package Phue\Generator\Commands
 */
final class MigrationGeneratorCommand implements CommandInterface
{
    use CommandHelperTrait;

    private MigrationGenerator $generator;
    private SchemaRegistry $schemaRegistry;

    /**
     * @param MigrationGenerator|null $generator
     * @param SchemaRegistry|null     $schemaRegistry
     */
    public function __construct(
        ?MigrationGenerator $generator = null,
        ?SchemaRegistry $schemaRegistry = null
    ) {
        $this->generator = $generator ?? new MigrationGenerator();
        $this->schemaRegistry = $schemaRegistry ?? new SchemaRegistry(getcwd());
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'generate:migration';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Generates a database migration file from a Schii schema.';
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
        $outputDir = getcwd();
        $driver = 'sqlite';
        $modelInput = null;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--output=')) {
                $outputDir = substr($arg, 9);
            } elseif (str_starts_with($arg, '--driver=')) {
                $driver = substr($arg, 9);
            } elseif (!str_starts_with($arg, '-')) {
                $modelInput ??= $arg;
            }
        }

        if ($modelInput === null) {
            fwrite($errorStream, "Error: Missing positional argument for model name or FQCN.\n");
            return CommandInterface::FAILURE;
        }

        try {
            $schema = $this->resolveSchema($this->schemaRegistry, $modelInput);
            $path = $this->generator->generateFromSchema($schema, $outputDir, $driver);

            fwrite($outputStream, "Generated migration at: {$path}\n");
            return CommandInterface::SUCCESS;
        } catch (\Throwable $e) {
            fwrite($errorStream, "Error: " . $e->getMessage() . "\n");
            return CommandInterface::FAILURE;
        }
    }
}