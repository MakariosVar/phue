<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Console\Command;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Schii\Migration\AutoMigrator;
use Phue\Schii\Schema\SchemaRegistry;

/**
 * CLI Command to auto-migrate database schema directly from Schii attributes.
 * Usage: ./bin/phue schii:migrate App\Models\Product
 */
final class SchiiMigrateCommand extends Command
{
    protected string $signature = 'schii:migrate {model : The fully qualified class name of the Model}';
    protected string $description = 'Auto-migrate database table based on Schii Model attributes';

    public function handle(SchemaRegistry $schemaRegistry): int
    {
        $modelClass = $this->argument('model');

        if (!class_exists($modelClass)) {
            $this->error("Model class '{$modelClass}' not found!");
            return 1;
        }

        if (!is_subclass_of($modelClass, BaseActiveRecord::class)) {
            $this->error("Class '{$modelClass}' must extend BaseActiveRecord.");
            return 1;
        }

        $this->info("Scanning Schii schema for '{$modelClass}'...");

        try {
            $migrator = new AutoMigrator($schemaRegistry, BaseActiveRecord::getConnection());
            $actions = $migrator->migrate($modelClass);

            if (empty($actions)) {
                $this->success("Database schema is already up to date!");
                return 0;
            }

            foreach ($actions as $action) {
                $this->line(" - {$action}");
            }

            $this->success("Schii Auto-Migration completed successfully!");
            return 0;
        } catch (\Throwable $e) {
            $this->error("Migration failed: " . $e->getMessage());
            return 1;
        }
    }
}