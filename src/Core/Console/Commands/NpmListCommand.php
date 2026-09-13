<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Core\Console\PathResolver;
use Phue\Core\Asset\NpmPackageManager;

/**
 * CLI Command to list all installed npm packages and their versions.
 */
final class NpmListCommand implements CommandInterface
{
    /**
     * Gets the unique command identifier name.
     */
    public function getName(): string
    {
        return 'npm:list';
    }

    /**
     * Gets a short description of the command for the CLI menu.
     */
    public function getDescription(): string
    {
        return 'Lists all installed npm packages and their versions.';
    }

    /**
     * Executes the npm list process.
     *
     * @param array<int, string> $args Command options.
     * @param resource $outputStream Standard output stream resource.
     * @param resource $errorStream Standard error stream resource.
     * @return int Status code (0 for success, non-zero for failure).
     */
    public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        $baseDir = PathResolver::resolveBasePath();
        $manager = new NpmPackageManager($baseDir);

        try {
            $packages = $manager->listPackages();

            if (empty($packages)) {
                fwrite($outputStream, "No installed npm packages found.\n");
                return 0;
            }

            fwrite($outputStream, "Installed Packages:\n");
            fwrite($outputStream, "┌──────────────────────────────┬──────────┬──────────────────────────────────────┐\n");
            fwrite($outputStream, "│ Package                      │ Version  │ ESM Path                             │\n");
            fwrite($outputStream, "├──────────────────────────────┼──────────┼──────────────────────────────────────┤\n");

            foreach ($packages as $name => $data) {
                $version = $data['version'] ?? 'unknown';
                $esmFile = $data['esm_file'] ?? '';
                $esmPath = sprintf('/assets/vendor/%s/%s', $name, $esmFile);

                fwrite($outputStream, sprintf("│ %-28s │ %-8s │ %-36s │\n", $name, $version, $esmPath));
            }

            fwrite($outputStream, "└──────────────────────────────┴──────────┴──────────────────────────────────────┘\n");
        } catch (\Throwable $e) {
            fwrite($errorStream, sprintf("\033[31mError: %s\033[0m\n", $e->getMessage()));
            return 1;
        }

        return 0;
    }
}