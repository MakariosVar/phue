<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Core\Console\PathResolver;

/**
 * CLI Command to remove the compiled routes cache file.
 *
 * @package Phue\Core\Console\Commands
 */
final class RouteClearCommand implements CommandInterface
{
    protected string $group = 'Dev';

    public function getGroup(): string
    {
        return $this->group;
    }

    public function getName(): string
    {
        return 'route:clear';
    }

    public function getDescription(): string
    {
        return 'Clears the compiled route cache file.';
    }

    public function execute(array $args = [], $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        $basePath = PathResolver::resolveBasePath();
        $cacheFile = $basePath . '/storage/cache/routes.php';

        if (file_exists($cacheFile) && unlink($cacheFile)) {
            fwrite($outputStream, "  ✓ Route cache cleared\n");
            return 0;
        }

        fwrite($outputStream, "  ! Route cache file not found or already cleared\n");
        return 0;
    }
}