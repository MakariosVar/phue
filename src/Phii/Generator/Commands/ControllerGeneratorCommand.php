<?php

declare(strict_types=1);

namespace Phue\Generator\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Generator\ControllerGenerator;

/**
 * Console command for generating resource/CRUD controllers.
 *
 * @package Phue\Generator\Commands
 */
final class ControllerGeneratorCommand implements CommandInterface
{
    use CommandHelperTrait;

    private ControllerGenerator $generator;

    /**
     * @param ControllerGenerator|null $generator
     */
    public function __construct(?ControllerGenerator $generator = null)
    {
        $this->generator = $generator ?? new ControllerGenerator();
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'generate:controller';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Generates a controller with routing attributes for a model.';
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
        $module = 'admin';
        $modelInput = null;
        $actions = ['index', 'store', 'show', 'update', 'delete'];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--output=')) {
                $outputDir = substr($arg, 9);
            } elseif (str_starts_with($arg, '--module=')) {
                $module = substr($arg, 9);
            } elseif (str_starts_with($arg, '--actions=')) {
                $actions = explode(',', substr($arg, 10));
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
            $modelName = (new \ReflectionClass($modelClass))->getShortName();

            $path = $this->generator->generate($module, $modelName, $outputDir, $actions);
            fwrite($outputStream, "Generated controller at: {$path}\n");
            return CommandInterface::SUCCESS;
        } catch (\Throwable $e) {
            fwrite($errorStream, "Error: " . $e->getMessage() . "\n");
            return CommandInterface::FAILURE;
        }
    }
}