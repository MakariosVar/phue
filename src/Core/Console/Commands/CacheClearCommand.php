<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Cache\FileCache;
use Phue\Core\Console\CommandInterface;
use Phue\Core\Console\PathResolver;

/**
 * CLI Command to clear application cache by type.
 *
 * Supports clearing specific cache categories or all caches at once.
 * Cache types:
 *   - general: PSR-16 file cache (storage/cache/*.cache)
 *   - views:   Compiled PHP views (storage/cache/views/*.php)
 *   - schemas: Generated database schemas (storage/generated/schemas/*)
 *   - logs:    Application logs (storage/logs/*)
 *
 * Usage:
 *   cache:clear                    # clears all (default)
 *   cache:clear --type=general     # clears only general cache
 *   cache:clear --type=views       # clears only compiled views
 *   cache:clear --type=schemas     # clears only generated schemas
 *   cache:clear --type=logs        # clears only logs
 *
 * @package Phue\Core\Console\Commands
 */
final class CacheClearCommand implements CommandInterface
{
    /**
     * Mapping of cache type aliases to their concrete implementations.
     *
     * @var array<string, list<string>>
     */
    private const TYPES = [
        'all'     => ['general', 'views', 'schemas', 'logs'],
        'general' => ['general'],
        'views'   => ['views'],
        'schemas' => ['schemas'],
        'logs'    => ['logs'],
    ];

    /**
     * Gets the unique command identifier name.
     *
     * @return string Command name for CLI registration.
     */
    public function getName(): string
    {
        return 'cache:clear';
    }

    /**
     * Gets a short description of the command for the CLI menu.
     *
     * @return string Human-readable description with usage hint.
     */
    public function getDescription(): string
    {
        return 'Clears application cache. Usage: cache:clear [--type=all|general|views|schemas|logs] (default: all)';
    }

    /**
     * Executes the cache clear process for the requested type(s).
     *
     * @param array<int, string> $args Command line arguments (supports --type=...).
     * @param resource           $outputStream Standard output stream for progress messages.
     * @param resource           $errorStream  Standard error stream for error messages.
     *
     * @return int Status code (0 = success, 1 = failure or invalid type).
     */
    public function execute(array $args = [], $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        $type = 'all';

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--type=')) {
                $type = substr($arg, 7);
            }
        }

        if (!isset(self::TYPES[$type])) {
            fwrite($errorStream, "Error: Invalid type '{$type}'. Valid: " . implode(', ', array_keys(self::TYPES)) . "\n");
            return 1;
        }

        $basePath = PathResolver::resolveBasePath();
        $cacheDir = $basePath . '/storage/cache';
        $viewsDir = $cacheDir . '/views';
        $schemasDir = $basePath . '/storage/generated/schemas';
        $logsDir = $basePath . '/storage/logs';

        $success = true;

        foreach (self::TYPES[$type] as $t) {
            switch ($t) {
                case 'general':
                    $fileCache = new FileCache($cacheDir);
                    $ok = $fileCache->clear();
                    fwrite($outputStream, $ok ? "  ✓ General cache cleared\n" : "  ✗ General cache clear failed\n");
                    $success &= $ok;
                    break;

                case 'views':
                    $files = glob($viewsDir . '/*.php');
                    $count = 0;
                    if ($files !== false) {
                        foreach ($files as $file) {
                            if (is_file($file) && unlink($file)) {
                                $count++;
                            }
                        }
                    }
                    fwrite($outputStream, "  ✓ Views cache cleared ({$count} files)\n");
                    break;

                case 'schemas':
                    $files = glob($schemasDir . '/*');
                    $count = 0;
                    if ($files !== false) {
                        foreach ($files as $file) {
                            if (is_file($file) && unlink($file)) {
                                $count++;
                            }
                        }
                    }
                    fwrite($outputStream, "  ✓ Schemas cache cleared ({$count} files)\n");
                    break;

                case 'logs':
                    $files = glob($logsDir . '/*');
                    $count = 0;
                    if ($files !== false) {
                        foreach ($files as $file) {
                            if (is_file($file) && unlink($file)) {
                                $count++;
                            }
                        }
                    }
                    fwrite($outputStream, "  ✓ Logs cleared ({$count} files)\n");
                    break;
            }
        }

        if ($success) {
            fwrite($outputStream, "Cache cleared successfully.\n");
            return 0;
        }

        fwrite($errorStream, "Cache clear completed with errors.\n");
        return 1;
    }
}