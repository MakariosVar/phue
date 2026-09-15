<?php

declare(strict_types=1);

namespace Phue\Generator\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Generator\ModuleGenerator;
use Phue\Phii\Generator\BackupManager;
use Phue\Phii\Generator\PhiiReport;

/**
 * Console command for generating module skeletons and config.
 *
 * @package Phue\Generator\Commands
 */
final class ModuleGeneratorCommand implements CommandInterface
{
    use CommandHelperTrait;

    private ModuleGenerator $generator;
    private BackupManager $backupManager;

    /**
     * @param ModuleGenerator|null $generator
     * @param BackupManager|null   $backupManager
     */
    public function __construct(
        ?ModuleGenerator $generator = null,
        ?BackupManager $backupManager = null
    ) {
        $this->generator = $generator ?? new ModuleGenerator();
        $this->backupManager = $backupManager ?? new BackupManager();
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

        $isPreview = false;
        $isForce = false;
        $doBackup = false;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--output=')) {
                $outputDir = substr($arg, 9);
            } elseif (str_starts_with($arg, '--auth=')) {
                $options['auth'] = filter_var(substr($arg, 7), FILTER_VALIDATE_BOOL);
            } elseif (str_starts_with($arg, '--api=')) {
                $options['api'] = filter_var(substr($arg, 6), FILTER_VALIDATE_BOOL);
            } elseif ($arg === '--preview') {
                $isPreview = true;
            } elseif ($arg === '--force') {
                $isForce = true;
            } elseif ($arg === '--backup') {
                $doBackup = true;
            } elseif (!str_starts_with($arg, '-')) {
                $moduleName ??= $arg;
            }
        }

        if ($moduleName === null) {
            fwrite($errorStream, "Error: Missing positional argument for module name.\n");
            return CommandInterface::FAILURE;
        }

        try {
            /** @var array<\Phue\Generator\FileMutation> $mutations */
            $mutations = $this->generator->generate($moduleName, $outputDir, $options);

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
                    'summary' => "Generate module skeleton component",
                ];
            }

            if ($isPreview) {
                fwrite($outputStream, json_encode(['plan' => $plan], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
                return CommandInterface::SUCCESS;
            }

            foreach ($mutations as $m) {
                if (file_exists($m->path) && !$isForce) {
                    fwrite($errorStream, "Error: File already exists at {$m->path}. Use --force to allow.\n");
                    return CommandInterface::FAILURE;
                }
            }

            $backupPath = null;
            if ($doBackup) {
                $backupPath = $this->backupManager->createBackup($outputDir);
            }

            $written = 0;
            fwrite($outputStream, "Module generated successfully. Created items:\n");
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