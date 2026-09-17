<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Core\Console\PathResolver;
use Phue\Core\Asset\NpmPackageManager;

/**
 * CLI Command to remove a previously installed npm package.
 */
final class NpmRemoveCommand implements CommandInterface
{
    /**
     * Gets the unique command identifier name.
     */
    public function getName(): string
    {
        return 'npm:remove';
    }

    /**
     * Gets a short description of the command for the CLI menu.
     */
    public function getDescription(): string
    {
        return 'Removes a previously installed npm package.';
    }

    /**
     * Executes the npm remove process.
     *
     * @param array<int, string> $args Command options.
     * @param resource $outputStream Standard output stream resource.
     * @param resource $errorStream Standard error stream resource.
     * @return int Status code (0 for success, non-zero for failure).
     */
    public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        $packageName = null;

        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--')) {
                $packageName = $arg;
                break;
            }
        }

        if ($packageName === null) {
            fwrite($errorStream, "\033[31mError: Please specify a package name to remove.\033[0m\n");
            fwrite($errorStream, "Usage: ./phue npm:remove <package>\n");
            return 1;
        }

        $baseDir = PathResolver::resolveBasePath();
        $manager = new NpmPackageManager($baseDir);

        try {
            fwrite($outputStream, sprintf("Removing package %s...\n", $packageName));
            $removed = $manager->remove($packageName);
            if (!$removed) {
                fwrite($errorStream, sprintf("\033[31mError: Package '%s' is not installed.\033[0m\n", $packageName));
                return 1;
            }
            fwrite($outputStream, sprintf("\033[32mSuccessfully removed %s\033[0m\n", $packageName));
        } catch (\Throwable $e) {
            fwrite($errorStream, sprintf("\033[31mError: %s\033[0m\n", $e->getMessage()));
            return 1;
        }

        return 0;
    }
}