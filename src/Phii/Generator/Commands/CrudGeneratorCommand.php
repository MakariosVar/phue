<?php

declare(strict_types=1);

namespace Phue\Generator\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Generator\CrudGenerator;
use Phue\Phii\Generator\BackupManager;
use Phue\Phii\Generator\PhiiReport;
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
    private BackupManager $backupManager;

    /**
     * @param CrudGenerator|null  $generator
     * @param SchemaRegistry|null $schemaRegistry
     * @param BackupManager|null  $backupManager
     */
    public function __construct(
        ?CrudGenerator $generator = null,
        ?SchemaRegistry $schemaRegistry = null,
        ?BackupManager $backupManager = null
    ) {
        $this->generator = $generator ?? new CrudGenerator();
        $this->schemaRegistry = $schemaRegistry ?? new SchemaRegistry(getcwd());
        $this->backupManager = $backupManager ?? new BackupManager();
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

        $isPreview = false;
        $isForce = false;
        $doBackup = false;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--output=')) {
                $basePath = substr($arg, 9);
            } elseif (str_starts_with($arg, '--path=')) {
                $basePath = substr($arg, 7);
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
            $schema = $this->resolveSchema($this->schemaRegistry, $modelInput, $module);
            
            /** @var array<\Phue\Generator\FileMutation> $mutations */
            $mutations = $this->generator->generate($schema, $module, $basePath);

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
                    'summary' => "CRUD component generation",
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
                $backupPath = $this->backupManager->createBackup($basePath);
            }

            $written = 0;
            fwrite($outputStream, "CRUD generation completed:\n");
            foreach ($mutations as $m) {
                if ($m->write()) {
                    $written++;
                    fwrite($outputStream, " - {$m->path}\n");
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