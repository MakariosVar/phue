<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Modules\Schii\Generator\BuildGenerator;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Throwable;

/**
 * Console command that exports Schii schema definitions into standalone Phii files.
 *
 * @package Phue\Modules\Schii\Commands
 */
final class SchiiExportCommand implements CommandInterface
{
    private ?SchemaRegistry $schemaRegistry;
    private ?BuildGenerator $buildGenerator;

    /**
     * SchiiExportCommand constructor.
     *
     * @param ?SchemaRegistry $schemaRegistry Registry to inspect and discover model classes.
     * @param ?BuildGenerator $buildGenerator Generator responsible for outputting Phii export schemas.
     */
    public function __construct(
        ?SchemaRegistry $schemaRegistry = null,
        ?BuildGenerator $buildGenerator = null,
    ) {
        $this->schemaRegistry = $schemaRegistry;
        $this->buildGenerator = $buildGenerator;
    }

    /**
     * The group this command belongs to.
     *
     * Commands in the same group are listed together in the help output.
     */
    protected string $group = 'Dev';
    
    /**
     * Gets the group this command belongs to.
     *
     * @return string Command group name.
     */
    public function getGroup(): string
    {
        return $this->group;
    }

    /**
     * Get the console command name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'schii:export';
    }

    /**
     * Get a short description of what the command does.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Exports Schii schema to standalone Phii files.';
    }

    /**
     * Execute the console command.
     *
     * Usage: phue schii:export [FQCN...] [--module=admin] [--output=/path] [--namespace=App\\Models]
     *
     * @param array<int, string>          $args         Command line arguments and flags.
     * @param resource|mixed              $outputStream Output stream (default STDOUT).
     * @param resource|mixed              $errorStream  Error stream (default STDERR).
     *
     * @return int Exit code (0 for success, non-zero for failure).
     */
    public function execute(
        array $args,
        $outputStream = \STDOUT,
        $errorStream = \STDERR,
    ): int {
        $parsed = $this->parseOptions($args);
        $positionals = $parsed['positionals'];
        $options = $parsed['options'];

        $module = $options['module'] ?? 'admin';
        $outputDir = $options['output'] ?? null;
        $namespace = $options['namespace'] ?? 'App\\Models';

        $schemaRegistry = $this->schemaRegistry ?? new SchemaRegistry();
        $buildGenerator = $this->buildGenerator ?? new BuildGenerator(
            $schemaRegistry,
            $outputDir ?? 'storage/generated/schemas'
        );

        try {
            $targetClasses = [];

            if (!empty($positionals)) {
                foreach ($positionals as $item) {
                    $fqcn = str_contains($item, '\\') ? $item : rtrim($namespace, '\\') . '\\' . $item;
                    if (class_exists($fqcn)) {
                        $targetClasses[] = $fqcn;
                    } else {
                        fwrite($errorStream, sprintf("Warning: Model class '%s' not found.%s", $fqcn, PHP_EOL));
                    }
                }
            } else {
                $targetClasses = $schemaRegistry->discoverModelClasses($namespace);
            }

            if (empty($targetClasses)) {
                fwrite($outputStream, sprintf("No model classes found to export in namespace '%s'.%s", $namespace, PHP_EOL));
                return self::SUCCESS;
            }

            foreach ($targetClasses as $modelClass) {
                $exportSchema = $schemaRegistry->toPhiiExportSchema($modelClass, $module);
                $buildGenerator->exportSchema($exportSchema);

                fwrite($outputStream, sprintf("Exported schema for '%s' (module: '%s')%s", $modelClass, $module, PHP_EOL));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            fwrite($errorStream, sprintf("Error exporting Schii schema: %s%s", $e->getMessage(), PHP_EOL));
            return self::FAILURE;
        }
    }

    /**
     * Parse CLI arguments into positional arguments and options (--key=value).
     *
     * @param array<int, string> $args Raw argument list passed to the command.
     *
     * @return array{positionals: array<int, string>, options: array<string, string|bool>}
     */
    private function parseOptions(array $args): array
    {
        $positionals = [];
        $options = [];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $param = substr($arg, 2);
                if (str_contains($param, '=')) {
                    [$key, $value] = explode('=', $param, 2);
                    $options[$key] = $value;
                } else {
                    $options[$param] = true;
                }
            } else {
                $positionals[] = $arg;
            }
        }

        return [
            'positionals' => $positionals,
            'options' => $options,
        ];
    }
}