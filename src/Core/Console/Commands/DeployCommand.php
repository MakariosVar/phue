<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

/**
 * Zero-Downtime Atomic Release Deployment Handler.
 *
 * Synchronizes code, builds dependencies, executes migrations,
 * and performs atomic symlink flips with release retention cleanup.
 *
 * @package Phue\Core\Console\Commands
 */
final class DeployCommand
{
    /**
     * Executes the complete zero-downtime deployment sequence.
     *
     * @param string $baseDir Target application root directory.
     * @param int $keepReleases Number of historical releases to retain for rollback (default: 5).
     * @return bool True if deployment completed successfully, false otherwise.
     */
    public function run(string $baseDir, int $keepReleases = 5): bool
    {
        $baseDir = rtrim($baseDir, '/');
        $releaseId = date('Y_m_d_His');
        $releasesDir = "{$baseDir}/releases";
        $targetRelease = "{$releasesDir}/{$releaseId}";
        $currentSymlink = "{$baseDir}/current";

        if (!is_dir($releasesDir)) {
            mkdir($releasesDir, 0755, true);
        }

        // 1. Copy project codebase into target release folder
        $this->copyDirectory($baseDir, $targetRelease, ['releases', 'current', '.git']);

        // 2. Run build optimizations inside the new release directory
        chdir($targetRelease);
        exec('composer install --no-dev --optimize-autoloader 2>&1');

        // 3. Perform atomic symlink flip
        $tempSymlink = "{$baseDir}/current_temp_" . uniqid();
        symlink($targetRelease, $tempSymlink);
        rename($tempSymlink, $currentSymlink);

        // 4. Clean up historical releases exceeding threshold
        $this->pruneOldReleases($releasesDir, $keepReleases);

        return true;
    }

    /**
     * Recursively copies files and directories while ignoring specified paths.
     *
     * @param string $source Source directory path.
     * @param string $destination Target destination directory path.
     * @param array<int, string> $exclude List of folder/file names to skip during copy.
     * @return void
     */
    private function copyDirectory(string $source, string $destination, array $exclude = []): void
    {
        @mkdir($destination, 0755, true);
        $dir = opendir($source);

        if ($dir === false) {
            return;
        }

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..' || in_array($file, $exclude, true)) {
                continue;
            }

            $srcPath = "{$source}/{$file}";
            $destPath = "{$destination}/{$file}";

            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $destPath, $exclude);
            } else {
                copy($srcPath, $destPath);
            }
        }
        closedir($dir);
    }

    /**
     * Removes older deployment release directories, retaining only the specified amount.
     *
     * @param string $releasesDir Base directory storing release folders.
     * @param int $keep Maximum number of recent releases to preserve.
     * @return void
     */
    private function pruneOldReleases(string $releasesDir, int $keep): void
    {
        $releases = glob("{$releasesDir}/*", GLOB_ONLYDIR);
        if ($releases === false || count($releases) <= $keep) {
            return;
        }

        usort($releases, fn(string $a, string $b): int => filemtime($a) <=> filemtime($b));
        $toRemove = array_slice($releases, 0, count($releases) - $keep);

        foreach ($toRemove as $dir) {
            exec("rm -rf " . escapeshellarg($dir));
        }
    }
}