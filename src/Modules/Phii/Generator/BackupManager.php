<?php

declare(strict_types=1);

namespace Phue\Modules\Phii\Generator;

use ZipArchive;

final class BackupManager
{
    /**
     * Create a ZIP backup of the given directory contents into storage/generated/backups/
     * with a timestamp and unique suffix. Returns null if ZipArchive extension is missing or creation fails.
     *
     * @param string $dir The directory path to back up.
     * @return string|null The absolute path to the created zip file, or null on failure.
     */
    public function createBackup(string $dir): ?string
    {
        if (!class_exists(ZipArchive::class) || !is_dir($dir)) {
            return null;
        }

        $backupDir = rtrim($dir, '/') . '/storage/generated/backups';
        if (!is_dir($backupDir)) {
            if (!mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
                return null;
            }
        }

        $timestamp = date('Y_m_d_His');
        $uniqueSuffix = bin2hex(random_bytes(4));
        $zipFilePath = $backupDir . '/backup_' . $timestamp . '_' . $uniqueSuffix . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            return null;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                continue;
            }

            $filePath = $file->getRealPath();

            // Skip the backups directory itself to avoid infinite recursion/bloat
            if (str_starts_with($filePath, realpath($backupDir))) {
                continue;
            }

            $relativePath = substr($filePath, strlen(realpath($dir)) + 1);

            $zip->addFile($filePath, $relativePath);
        }

        $success = $zip->close();

        return $success ? $zipFilePath : null;
    }
}
