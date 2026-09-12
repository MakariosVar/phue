<?php

declare(strict_types=1);

namespace Phue\Core\Console;

/**
 * Path Resolver Helper for CLI Commands.
 * Implements Discovery Hierarchy for root path resolution:
 * 1. Explicit constant PHUE_BASE_PATH if defined
 * 2. Upward directory traversal searching for application markers (composer.json, config/, App/)
 * 3. Fallback to getcwd()
 */
final class PathResolver
{
    /**
     * Resolves absolute base path of the project application.
     *
     * @return string
     */
    public static function resolveBasePath(): string
    {
        // 1. Explicitly Defined Constant
        if (defined('PHUE_BASE_PATH') && is_string(PHUE_BASE_PATH) && PHUE_BASE_PATH !== '') {
            return PHUE_BASE_PATH;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            return '.';
        }

        // 2. Upward Discovery Marker Traversal
        $current = $cwd;
        while (true) {
            if (
                file_exists($current . '/composer.json') ||
                is_dir($current . '/config') ||
                is_dir($current . '/App')
            ) {
                return $current;
            }

            $parent = dirname($current);
            if ($parent === $current) { // Reached filesystem root
                break;
            }
            $current = $parent;
        }

        // 3. Fallback to Current Working Directory
        return $cwd;
    }
}