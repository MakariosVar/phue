<?php

namespace Phue\Core\Console\Commands;

use Phue\Core\Console\CommandInterface;

/**
 * CLI Command to boot the local PHP built-in development web server.
 *
 * @package Phue\Core\Console\Commands
 */
class ServeCommand implements CommandInterface
{
    /**
     * Gets the unique command identifier name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'serve';
    }

    /**
     * Gets a short description of the command for the CLI menu.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Starts the PHP development server (default: http://localhost:8000).';
    }

    /**
     * Executes the built-in development server process with optional --host and --port overrides.
     *
     * @param array<int, string> $args Command options (e.g. ['--host=0.0.0.0', '--port=8080']).
     * @return int Status code (0 for success).
     */
    public function execute(array $args): int
    {
        $host = '127.0.0.1';
        $port = '8000';

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--port=')) {
                $port = substr($arg, 7);
            } elseif (str_starts_with($arg, '--host=')) {
                $host = substr($arg, 7);
            }
        }

        $docRoot = is_dir(getcwd() . '/public') ? 'public' : '.';
        $command = sprintf('php -S %s:%s -t %s', $host, $port, $docRoot);

        echo sprintf("\033[32mPhue Development Server started:\033[0m http://%s:%s\n", $host, $port);
        echo "Press Ctrl+C to stop the server.\n\n";

        passthru($command);

        return 0;
    }
}