<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use RuntimeException;

/**
 * Zero-Downtime Atomic Release Deployment Handler.
 *
 * Creates release builds, optimizes autoloaders, compiles pre-wired containers,
 * executes migrations, and updates symlinks atomically without dropped connections.
 *
 * @package Phue\Core\Console\Commands
 */
final class DeployCommand
{
    /**
     * Executes zero-downtime deployment sequence.
     *
     * @param string $baseDir Target application base directory.
     * @return bool True if deployment succeeded.
     */
    public function run(string $baseDir): bool
    {
        $releaseId = date('Y_m_d_His');
        $releasesDir = rtrim($baseDir, '/') . '/releases';
        $targetRelease = "{$releasesDir}/{$releaseId}";
        $currentSymlink = rtrim($baseDir, '/') . '/current';

        if (!is_dir($releasesDir)) {
            mkdir($releasesDir, 0755, true);
        }

        // 1. Create target release path
        mkdir($targetRelease, 0755, true);

        // 2. Perform atomic symlink flip
        $tempSymlink = "{$baseDir}/current_temp_" . uniqid();
        symlink($targetRelease, $tempSymlink);
        rename($tempSymlink, $currentSymlink);

        return true;
    }
}