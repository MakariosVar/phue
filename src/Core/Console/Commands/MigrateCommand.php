<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Core\Database\Connection;
use Phue\Core\Database\Migrator;

/**
 * CLI Command to run pending explicit database migrations.
 *
 * @package Phue\Core\Console\Commands
 */
final class MigrateCommand implements CommandInterface
{
    /**
     * Retrieves command identifier name.
     *
     * @return string Command name.
     */
    public function getName(): string
    {
        return 'migrate';
    }

    /**
     * Retrieves command description.
     *
     * @return string Command description.
     */
    public function getDescription(): string
    {
        return 'Runs all pending database migrations.';
    }

    /**
     * Executes pending migrations engine.
     *
     * @param array<int, string> $args Command arguments.
     * @return int Exit code (0 for success, 1 for failure).
     */
    public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        $db = $this->resolveConnection();
        $path = getcwd() . '/database/migrations';

        $migrator = new Migrator($db, $path);

        fwrite($outputStream, "Running pending migrations...\n");

        try {
            $applied = $migrator->run();

            if (empty($applied)) {
                fwrite($outputStream, "Nothing to migrate.\n");
                return 0;
            }

            foreach ($applied as $migration) {
                fwrite($outputStream, "Migrated: {$migration}\n");
            }

            return 0;
        } catch (\Throwable $e) {
            fwrite($errorStream, "Migration error: " . $e->getMessage() . "\n");
            return 1;
        }
    }

    /**
     * Resolves configured application database connection instance.
     *
     * @return Connection
     */
    private function resolveConnection(): Connection
    {
        return new Connection([
            'driver' => 'sqlite',
            'database' => getcwd() . '/database/database.sqlite',
        ]);
    }
}