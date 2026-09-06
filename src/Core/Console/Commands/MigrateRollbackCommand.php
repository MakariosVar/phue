<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Config;
use Phue\Core\Console\CommandInterface;
use Phue\Core\Database\Connection;
use Phue\Core\Database\Migrator;
use \RuntimeException;

/**
 * CLI Command to rollback the last batch of executed migrations.
 *
 * @package Phue\Core\Console\Commands
 */
final class MigrateRollbackCommand implements CommandInterface
{
    /**
     * Retrieves command identifier name.
     *
     * @return string Command name.
     */
    public function getName(): string
    {
        return 'migrate:rollback';
    }

    /**
     * Retrieves command description.
     *
     * @return string Command description.
     */
    public function getDescription(): string
    {
        return 'Rolls back the last batch of executed migrations.';
    }

    /**
     * Executes migration batch rollback.
     *
     * @param array<int, string> $args Command arguments.
     * @return int Exit code (0 for success, 1 for failure).
     */
    public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        $db = $this->resolveConnection();
        $path = getcwd() . '/database/migrations';

        $migrator = new Migrator($db, $path);

        fwrite($outputStream, "Rolling back last migration batch...\n");

        try {
            $rolledBack = $migrator->rollback();

            if (empty($rolledBack)) {
                fwrite($outputStream, "Nothing to rollback.\n");
                return 0;
            }

            foreach ($rolledBack as $migration) {
                fwrite($outputStream, "Rolled back: {$migration}\n");
            }

            return 0;
        } catch (\Throwable $e) {
            fwrite($errorStream, "Rollback error: " . $e->getMessage() . "\n");
            return 1;
        }
    }

    /**
     * Resolves application database connection directly from config.
     *
     * @throws RuntimeException If config or connection definitions are missing.
     * @return Connection
     */
    private function resolveConnection(): Connection
    {
        $configPath = getcwd() . "/config";

        if (!is_dir($configPath)) {
            throw new RuntimeException(
                "Config directory missing at: {$configPath}",
            );
        }

        $config = new Config($configPath);

        $defaultDriver = $config->get("database.default");
        if (!$defaultDriver || !is_string($defaultDriver)) {
            throw new RuntimeException(
                "Database connection not configured: 'database.default' is missing.",
            );
        }

        $dbConfig = $config->get("database.connections.{$defaultDriver}");
        if (!$dbConfig || !is_array($dbConfig)) {
            throw new RuntimeException(
                "Database connection not configured: 'database.connections.{$defaultDriver}' is missing or invalid.",
            );
        }

        return new Connection($dbConfig);
    }
}