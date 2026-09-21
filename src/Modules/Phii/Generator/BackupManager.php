<?php

declare(strict_types=1);

namespace Phue\Modules\Phii\Generator;

use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

/**
 * Class BackupManager
 *
 * Manages project backups, listing existing archives, and restoring backups safely.
 *
 * @package Phue\Modules\Phii\Generator
 */
final class BackupManager
{
    /**
     * Create a ZIP backup of the given output root directory contents.
     * Prevents double nesting by storing backups cleanly in `{dir}/backups/`.
     *
     * @param string $dir The root directory path to back up.
     * @return string|null The absolute path to the created zip file, or null on failure.
     */
    public function createBackup(string $dir): ?string
    {
        if (!class_exists(ZipArchive::class) || !is_dir($dir)) {
            return null;
        }

        $dir = rtrim($dir, DIRECTORY_SEPARATOR);
        $backupDir =$dir . DIRECTORY_SEPARATOR . 'backups';

        if (!is_dir($backupDir)) {
            if (!mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
                return null;
            }
        }

        $timestamp = date('Y_m_d_His');
        $uniqueSuffix = bin2hex(random_bytes(4));
        $zipFilePath = $backupDir . DIRECTORY_SEPARATOR . 'backup_' . $timestamp . '_' .$uniqueSuffix . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            return null;
        }

        $realDir = realpath($dir);
        $realBackupDir = realpath($backupDir);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as$file) {
            if ($file->isDir()) {
                continue;
            }

            $filePath =$file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            // Skip the backups directory itself to avoid recursion/bloat
            if ($realBackupDir && str_starts_with($filePath, $realBackupDir)) {
                continue;
            }

            $relativePath = substr($filePath, strlen($realDir) + 1);
            $zip->addFile($filePath, $relativePath);
        }

        $success = $zip->close();

        return $success ? $zipFilePath : null;
    }

    /**
     * List all available backup ZIP archives within the target directory backups folder.
     *
     * @param string $dir The root directory path.
     * @return array<int, array{filename: string, path: string, size: int, timestamp: int}> List of backups.
     */
    public function listBackups(string $dir): array
    {
        $dir = rtrim($dir, DIRECTORY_SEPARATOR);
        $backupDir =$dir . DIRECTORY_SEPARATOR . 'backups';

        if (!is_dir($backupDir)) {
            return [];
        }

        $backups = [];
        $iterator = new \DirectoryIterator($backupDir);

        foreach ($iterator as$fileInfo) {
            if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'zip') {$backups[] = [
                    'filename' => $fileInfo->getFilename(),
                    'path' => $fileInfo->getRealPath(),
                    'size' => $fileInfo->getSize(),
                    'timestamp' => $fileInfo->getMTime(),
                ];
            }
        }

        // Sort by newest first
        usort($backups, fn($a,$b) => $b['timestamp'] <=>$a['timestamp']);

        return $backups;
    }

    /**
     * Restore a project from a specified backup ZIP archive.
     *
     * @param string $zipFilePath Absolute path to the backup zip archive.
     * @param string $targetDir   Target extraction directory.
     * @return bool True on successful extraction, false otherwise.
     */
    public function restore(string $zipFilePath, string $targetDir): bool
    {
        if (!class_exists(ZipArchive::class) || !file_exists($zipFilePath) || !is_dir($targetDir)) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFilePath) !== true) {
            return false;
        }

        $targetDir = rtrim($targetDir, DIRECTORY_SEPARATOR);
        $realTargetDir = realpath($targetDir);

        if ($realTargetDir === false) {
            $zip->close();
            return false;
        }

        // Iterate through all entries to validate paths before extraction (Zip Slip protection)
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }

            $name = $stat['name'];

            // Check for relative path traversal attempts ('..') or absolute paths
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_starts_with($name, '\\')) {
                $zip->close();
                throw new \DomainException("Security Violation: Path traversal attempt detected in ZIP entry '{$name}'.");
            }

            $destinationPath = $targetDir . DIRECTORY_SEPARATOR . $name;
            $dirPart = dirname($destinationPath);

            // If directory doesn't exist yet, validate its parent or simulate realpath containment
            $realDirPart = realpath($dirPart);
            if ($realDirPart === false) {
                // For nested directories that don't exist yet, check up the tree or validate base parts
                $tempDir = $dirPart;
                while (!is_dir($tempDir) && $tempDir !== $targetDir) {
                    $tempDir = dirname($tempDir);
                }
                $realDirPart = realpath($tempDir) ?: $tempDir;
            }

            if (!str_starts_with($realDirPart, $realTargetDir)) {
                $zip->close();
                throw new \DomainException("Security Violation: Path escapes target directory for entry '{$name}'.");
            }
        }

        // Safe to proceed with extraction
        $success = $zip->extractTo($targetDir);
        $zip->close();

        return $success;
    }
}