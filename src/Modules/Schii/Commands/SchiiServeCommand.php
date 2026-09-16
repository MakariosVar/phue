<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Core\Routing\Router;
use Phue\Modules\Schii\Generator\RuntimeGenerator;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Throwable;

/**
 * Console command that serves Schii API and console from schema at runtime.
 *
 * @package Phue\Modules\Schii\Commands
 */
final class SchiiServeCommand implements CommandInterface
{

    private ?SchemaRegistry $schemaRegistry;
    private ?RuntimeGenerator $runtimeGenerator;


    /**
     * SchiiServeCommand constructor.
     *
     * @param ?RuntimeGenerator $runtimeGenerator Generator to register runtime API and UI routes.
     * @param ?RuntimeGenerator   $schemaRegistry   Registry for entity and namespace discovery.
     */
    public function __construct(
        ?SchemaRegistry $schemaRegistry = null,
        ?RuntimeGenerator $runtimeGenerator = null,
    ) {
        $this->schemaRegistry = $schemaRegistry;
        $this->runtimeGenerator = $runtimeGenerator;
    }

    /**
     * Get the console command name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'schii:serve';
    }

    /**
     * Get a short description of what the command does.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Serves Schii API + console from schema at runtime.';
    }

    /**
     * Execute the console command.
     *
     * Usage: phue schii:serve [--host=0.0.0.0] [--port=8000] [--entities=App\\Models] [--public=public] [--api-prefix=/api/schii]
     *
     * @param array<int, string>          $args         Command line arguments and flags.
     * @param resource|mixed              $outputStream Output stream (default STDOUT).
     * @param resource|mixed              $errorStream  Error stream (default STDERR).
     *
     * @return int Exit code (self::SUCCESS or self::FAILURE).
     */
    public function execute(
        array $args,
        $outputStream = \STDOUT,
        $errorStream = \STDERR,
    ): int {
        $parsed = $this->parseOptions($args);
        $options = $parsed['options'];

        $host = (string) ($options['host'] ?? '0.0.0.0');
        $port = (int) ($options['port'] ?? 8000);
        $namespace = (string) ($options['entities'] ?? 'App\\Models');
        $publicDir = (string) ($options['public'] ?? 'public');
        $apiPrefix = (string) ($options['api-prefix'] ?? '/api/schii');

        try {
            $targetDir = realpath($publicDir) ?: getcwd() . '/' . $publicDir;

            if (!is_dir($targetDir)) {
                fwrite($errorStream, sprintf("Error: Public directory '%s' does not exist.%s", $targetDir, PHP_EOL));
                return self::FAILURE;
            }

            $address = sprintf('%s:%d', $host, $port);
            fwrite($outputStream, sprintf("Starting Schii runtime server at http://%s (document root: %s)%s", $address, $targetDir, PHP_EOL));
            fwrite($outputStream, sprintf("Scanning entities in namespace: %s%s", $namespace, PHP_EOL));
            fwrite($outputStream, sprintf("API prefix: %s%s", $apiPrefix, PHP_EOL));
            fwrite($outputStream, "Press Ctrl+C to stop the server." . PHP_EOL);

            // Note: php -S runs in its own process/request cycle.
            // Routes must be registered inside the HTTP bootstrap (e.g. public/index.php or a router script),
            // or php -S must use a router script that boots the application and calls registerAll($apiPrefix, $namespace).
            $command = sprintf(
                'php -S %s -t %s',
                escapeshellarg($address),
                escapeshellarg($targetDir)
            );

            passthru($command, $exitCode);

            return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            fwrite($errorStream, sprintf("Error starting Schii server: %s%s", $e->getMessage(), PHP_EOL));
            return self::FAILURE;
        }
    }

    /**
     * Parse CLI arguments into positional arguments and options (--key=value).
     *
     * @param array<int, string> $args Raw argument list passed to the command.
     *
     * @return array{positionals: array<int, string>, options: array<string, string|bool>}
     */
    private function parseOptions(array $args): array
    {
        $positionals = [];
        $options = [];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $param = substr($arg, 2);
                if (str_contains($param, '=')) {
                    [$key, $value] = explode('=', $param, 2);
                    $options[$key] = $value;
                } else {
                    $options[$param] = true;
                }
            } else {
                $positionals[] = $arg;
            }
        }

        return [
            'positionals' => $positionals,
            'options' => $options,
        ];
    }
}
