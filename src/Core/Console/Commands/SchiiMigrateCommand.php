<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Core\Database\Connection;
use Phue\Schii\Migration\AutoMigrator;
use Phue\Schii\Schema\SchemaRegistry;

/**
 * CLI Command to run Schii declarative auto-migration for a target model class.
 *
 * @package Phue\Core\Console\Commands
 */
final class SchiiMigrateCommand implements CommandInterface
{
    /**
     * Retrieves command identifier name.
     *
     * @return string Command name.
     */
    public function getName(): string
    {
        return 'schii:migrate';
    }

    /**
     * Retrieves command description.
     *
     * @return string Command description.
     */
    public function getDescription(): string
    {
        return 'Runs declarative Schii auto-migration for a specific ActiveRecord model class.';
    }

    /**
     * Executes declarative auto-migration on the requested model class.
     *
     * @param array<int, string> $args Command arguments containing model class.
     * @return int Exit code (0 for success, 1 for failure).
     */
    public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        $modelClass = $args[0] ?? null;

        if (!$modelClass) {
            fwrite($errorStream, "Error: Model class name is required. Usage: ./bin/phue schii:migrate App\\Models\\Product\n");
            return 1;
        }

        if (!class_exists($modelClass)) {
            fwrite($errorStream, "Error: Model class '{$modelClass}' not found.\n");
            return 1;
        }

        try {
            $connection = $this->resolveConnection();
            $registry = new SchemaRegistry();
            $migrator = new AutoMigrator($registry, $connection);

            $actions = $migrator->migrate($modelClass);

            if (empty($actions)) {
                fwrite($outputStream, "Schema up to date for '{$modelClass}'. No changes applied.\n");
                return 0;
            }

            foreach ($actions as $action) {
                fwrite($outputStream, "[Schii] {$action}\n");
            }

            return 0;
        } catch (\Throwable $e) {
            fwrite($errorStream, "Schii Migration Error: " . $e->getMessage() . "\n");
            return 1;
        }
    }

    /**
     * Resolves database connection instance.
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