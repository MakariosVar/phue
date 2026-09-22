<?php

declare(strict_types=1);

namespace Phue\Modules\Phii\Generator\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Modules\Phii\Generator\ModuleGenerator;
use Phue\Modules\Phii\Generator\BackupManager;
use Phue\Modules\Phii\Generator\PhiiReport;

/**
 * Console command for generating module skeletons and config.
 *
 * @package Phue\Modules\Phii\Generator\Commands
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
     * The group this command belongs to.
     *
     * Commands in the same group are listed together in the help output.
     */
    protected string $group = 'Build';
    
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

        $isPreview = false;
        $isForce = false;
        $doBackup = false;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--output=')) {
                $outputDir = substr($arg, 9);
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
            /** @var array<\Phue\Modules\Phii\Generator\FileMutation> $mutations */
            $mutations = $this->generator->generate($moduleName, $outputDir);

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