<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Core\Console\PathResolver;
use Phue\Core\Asset\NpmPackageManager;

/**
 * CLI Command to install or restore npm packages as browser-ready ESM bundles.
 */
final class NpmInstallCommand implements CommandInterface
{
    /**
     * The group this command belongs to.
     *
     * Commands in the same group are listed together in the help output.
     */
    protected string $group = 'Dev';
    
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
     * Gets the unique command identifier name.
     */
    public function getName(): string
    {
        return 'npm:install';
    }

    /**
     * Gets a short description of the command for the CLI menu.
     */
    public function getDescription(): string
    {
        return 'Downloads and builds an npm package as browser-ready ESM module (or restores all if none specified).';
    }

    /**
     * Executes the npm install or restore process.
     *
     * @param array<int, string> $args Command options.
     * @param resource $outputStream Standard output stream resource.
     * @param resource $errorStream Standard error stream resource.
     * @return int Status code (0 for success, non-zero for failure).
     */
    public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        $packageName = null;
        $version = 'latest';

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--version=')) {
                $version = substr($arg, 10);
            } elseif (!str_starts_with($arg, '--')) {
                $packageName = $arg;
            }
        }

        $baseDir = PathResolver::resolveBasePath();
        $manager = new NpmPackageManager($baseDir);

        try {
            if ($packageName === null) {
                fwrite($outputStream, "Restoring packages from phue-packages.json...\n");
                $manager->restore();
                fwrite($outputStream, "\033[32mSuccessfully restored all packages.\033[0m\n");
            } else {
                if (str_contains($packageName, '@') && !str_starts_with($packageName, '@')) {
                    [$packageName, $version] = explode('@', $packageName, 2);
                } elseif (str_starts_with($packageName, '@') && substr_count($packageName, '@') > 1) {
                    $lastAt = strrpos($packageName, '@');
                    $version = substr($packageName, $lastAt + 1);
                    $packageName = substr($packageName, 0, $lastAt);
                }

                fwrite($outputStream, sprintf("Installing package %s@%s...\n", $packageName, $version));
                $manager->install($packageName, $version);
                fwrite($outputStream, sprintf("\033[32mSuccessfully installed and built %s\033[0m\n", $packageName));
            }
        } catch (\Throwable $e) {
            fwrite($errorStream, sprintf("\033[31mError: %s\033[0m\n", $e->getMessage()));
            return 1;
        }

        return 0;
    }
}