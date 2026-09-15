<?php

declare(strict_types=1);

namespace Phue\Generator\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Generator\ControllerGenerator;
use Phue\Phii\Generator\BackupManager;
use Phue\Phii\Generator\PhiiReport;

/**
 * Console command for generating resource/CRUD controllers.
 *
 * @package Phue\Generator\Commands
 */
final class ControllerGeneratorCommand implements CommandInterface
{
    use CommandHelperTrait;

    private ControllerGenerator $generator;
    private BackupManager $backupManager;

    /**
     * @param ControllerGenerator|null $generator
     * @param BackupManager|null       $backupManager
     */
    public function __construct(
        ?ControllerGenerator $generator = null,
        ?BackupManager $backupManager = null
    ) {
        $this->generator = $generator ?? new ControllerGenerator();
        $this->backupManager = $backupManager ?? new BackupManager();
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
        
        $isPreview = false;
        $isForce = false;
        $doBackup = false;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--output=')) {
                $outputDir = substr($arg, 9);
            } elseif (str_starts_with($arg, '--module=')) {
                $module = substr($arg, 9);
            } elseif (str_starts_with($arg, '--actions=')) {
                $actions = explode(',', substr($arg, 10));
            } elseif ($arg === '--preview') {
                $isPreview = true;
            } elseif ($arg === '--force') {
                $isForce = true;
            } elseif ($arg === '--backup') {
                $doBackup = true;
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

            $targetDir = rtrim($outputDir, '/') . "/App/Modules/{$module}/Controller";

            /** @var array<\Phue\Generator\FileMutation> $mutations */
            $mutations = $this->generator->generate($module, $modelName, $targetDir, $actions);

            $plan = [];
            $conflicts = 0;

            foreach ($mutations as $m) {
                $exists = file_exists($m->path);
                if ($exists) {
                    $conflicts++;
                }
                $plan[] = [
                    'path' => $m->path,
                    'exists' => $exists,
                    'willOverwrite' => $exists || $isForce,
                    'summary' => "Generate controller for {$modelName}",
                ];
            }

            if ($isPreview) {
                fwrite($outputStream, json_encode(['plan' => $plan], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
                return CommandInterface::SUCCESS;
            }

            foreach ($mutations as $m) {
                if (file_exists($m->path) && !$isForce) {
                    fwrite($errorStream, "Error: File already exists at {$m->path}. Use --force to overwrite.\n");
                    return CommandInterface::FAILURE;
                }
            }

            $backupPath = null;
            if ($doBackup) {
                $backupPath = $this->backupManager->createBackup($outputDir);
            }

            $written = 0;
            foreach ($mutations as $m) {
                if ($m->write()) {
                    $written++;
                    fwrite($outputStream, "Generated controller at: {$m->path}\n");
                }
            }

            $report = new PhiiReport(
                written: $written,
                skipped: count($mutations) - $written,
                conflicts: $conflicts,
                backupPath: $backupPath
            );

            fwrite($outputStream, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");

            return CommandInterface::SUCCESS;
        } catch (\Throwable $e) {
            fwrite($errorStream, "Error: " . $e->getMessage() . "\n");
            return CommandInterface::FAILURE;
        }
    }
}