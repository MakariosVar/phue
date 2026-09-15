<?php

declare(strict_types=1);

namespace Phue\Generator\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Generator\ModelGenerator;
use Phue\Phii\Generator\BackupManager;
use Phue\Phii\Generator\PhiiReport;
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
    private BackupManager $backupManager;

    /**
     * @param ModelGenerator|null $generator
     * @param SchemaRegistry|null $schemaRegistry
     * @param BackupManager|null  $backupManager
     */
    public function __construct(
        ?ModelGenerator $generator = null,
        ?SchemaRegistry $schemaRegistry = null,
        ?BackupManager $backupManager = null
    ) {
        $this->generator = $generator ?? new ModelGenerator();
        $this->schemaRegistry = $schemaRegistry ?? new SchemaRegistry(getcwd());
        $this->backupManager = $backupManager ?? new BackupManager();
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

        $isPreview = false;
        $isForce = false;
        $doBackup = false;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--output=')) {
                $outputDir = substr($arg, 9);
            } elseif (str_starts_with($arg, '--type=')) {
                $type = substr($arg, 7);
            } elseif (str_starts_with($arg, '--module=')) {
                $module = substr($arg, 9);
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
            $schema = $this->schemaRegistry->toPhiiExportSchema($modelClass, $module);

            $targetDir = rtrim($outputDir, '/') . '/App/Models';

            /** @var array<\Phue\Generator\FileMutation> $mutations */
            $mutations = $this->generator->generateFromSchema($schema, $type, $targetDir);

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
                    'summary' => "Generate model class ({$type})",
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
                    fwrite($outputStream, "Generated model at: {$m->path}\n");
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