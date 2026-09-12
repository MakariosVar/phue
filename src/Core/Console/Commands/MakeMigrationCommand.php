<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Core\Console\PathResolver;

/**
 * CLI Command to generate boilerplate database migration files with timestamps.
 *
 * @package Phue\Core\Console\Commands
 */
final class MakeMigrationCommand implements CommandInterface
{
    /**
     * Retrieves command identifier name.
     *
     * @return string Command name.
     */
    public function getName(): string
    {
        return 'make:migration';
    }

    /**
     * Retrieves command description.
     *
     * @return string Command description.
     */
    public function getDescription(): string
    {
        return 'Creates a new explicit migration file.';
    }

    /**
     * Executes migration template generation.
     *
     * @param array<int, string> $args Command line arguments.
     * @return int Exit code (0 for success, 1 for failure).
     */
    public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        $name = $args[0] ?? null;

        if (!$name) {
            fwrite($errorStream, "Error: Migration name is required. Usage: ./bin/phue make:migration <name>\n");
            return 1;
        }

        $timestamp = date('Y_m_d_His');
        $cleanName = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($name));
        $filename = "m{$timestamp}_{$cleanName}.php";

        $className = implode('', array_map('ucfirst', explode('_', "m{$timestamp}_{$cleanName}")));

        $migrationsDir = PathResolver::resolveBasePath() . "/database/migrations";

        if (!is_dir($migrationsDir)) {
            mkdir($migrationsDir, 0755, true);
        }

        $targetPath = $migrationsDir . DIRECTORY_SEPARATOR . $filename;

        $template = <<<PHP
<?php

declare(strict_types=1);

use Phue\Core\Database\Migration;

/**
 * Migration: {$className}
 */
final class {$className} extends Migration
{
    /**
     * Execute up schema migrations.
     */
    public function up(): void
    {
        // \$this->execute("CREATE TABLE example (id INT PRIMARY KEY);");
    }

    /**
     * Execute down schema rollbacks.
     */
    public function down(): void
    {
        // \$this->execute("DROP TABLE IF EXISTS example;");
    }
}
PHP;

        file_put_contents($targetPath, $template);

        fwrite($outputStream, "Created Migration: {$targetPath}\n");
        return 0;
    }
}