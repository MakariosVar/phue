<?php

declare(strict_types=1);

namespace Phue\Generator\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Generator\ModuleGenerator;

/**
 * Console command for generating module skeletons and config.
 *
 * @package Phue\Generator\Commands
 */
final class ModuleGeneratorCommand implements CommandInterface
{
    private ModuleGenerator $generator;

    /**
     * @param ModuleGenerator|null $generator
     */
    public function __construct(?ModuleGenerator $generator = null)
    {
        $this->generator = $generator ?? new ModuleGenerator();
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'generate:module';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Generates a new module directory skeleton and config.';
    }

    /**
     * Execute console command handling moduleName positional argument and flags.
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
        $moduleName = null;
        $options = ['auth' => false, 'api' => false];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--output=')) {
                $outputDir = substr($arg, 9);
            } elseif (str_starts_with($arg, '--auth=')) {
                $options['auth'] = filter_var(substr($arg, 7), FILTER_VALIDATE_BOOL);
            } elseif (str_starts_with($arg, '--api=')) {
                $options['api'] = filter_var(substr($arg, 6), FILTER_VALIDATE_BOOL);
            } elseif (!str_starts_with($arg, '-')) {
                $moduleName ??= $arg;
            }
        }

        if ($moduleName === null) {
            fwrite($errorStream, "Error: Missing positional argument for module name.\n");
            return CommandInterface::FAILURE;
        }

        try {
            $created = $this->generator->generate($moduleName, $outputDir, $options);
            fwrite($outputStream, "Module generated successfully. Created items:\n");
            foreach ($created as $item) {
                fwrite($outputStream, " - {$item}\n");
            }
            return CommandInterface::SUCCESS;
        } catch (\Throwable $e) {
            fwrite($errorStream, "Error: " . $e->getMessage() . "\n");
            return CommandInterface::FAILURE;
        }
    }
}