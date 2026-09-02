<?php

declare(strict_types=1);

namespace Phue\Tests\Database;

use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Connection;
use Phue\Core\Database\Migrator;
use Phue\Core\Console\Commands\MakeMigrationCommand;

/**
 * Integration & Unit Tests for the Explicit Migrations Subsystem.
 *
 * Verifies batch processing, transaction safety, up/down execution, and CLI command execution.
 *
 * @package Phue\Tests\Database
 */
class MigratorTest extends TestCase
{
    private Connection $db;
    private string $tempMigrationsDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Initialize in-memory SQLite database connection
        $this->db = new Connection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        // Create isolated temporary directory for dummy migration files per test run
        $this->tempMigrationsDir = sys_get_temp_dir() . '/phue_migrations_' . uniqid('', true);
        if (!is_dir($this->tempMigrationsDir)) {
            mkdir($this->tempMigrationsDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up temporary files and directory after each test execution
        if (is_dir($this->tempMigrationsDir)) {
            $files = glob($this->tempMigrationsDir . '/*') ?: [];
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->tempMigrationsDir);
        }

        parent::tearDown();
    }

    /**
     * Test that the migrator creates the tracking table and runs pending migrations in batch order.
     */
    public function testRunExecutesPendingMigrationsInBatch(): void
    {
        // $m1 created first (timestamp +0s)
        $m1 = $this->createUniqueDummyMigrationFile(
            'create_users_table',
            "CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT);",
            "DROP TABLE users;",
            0
        );

        // $m2 created second (timestamp +1s) ensuring deterministic chronological sorting
        $m2 = $this->createUniqueDummyMigrationFile(
            'create_posts_table',
            "CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT);",
            "DROP TABLE posts;",
            1
        );

        $migrator = new Migrator($this->db, $this->tempMigrationsDir);
        $applied = $migrator->run();

        $this->assertCount(2, $applied);
        $this->assertEquals($m1['filename'], $applied[0]);
        $this->assertEquals($m2['filename'], $applied[1]);

        // Verify tables exist in DB
        $stmt = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('users', 'posts')");
        $results = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : (array) $stmt;
        $this->assertCount(2, $results);

        // Subsequent run should execute zero migrations
        $this->assertEmpty($migrator->run());
    }

    /**
     * Test that rollback reverts only the most recent batch of executed migrations.
     */
    public function testRollbackRevertsLastBatchOnly(): void
    {
        // Batch 1 Migration
        $m1 = $this->createUniqueDummyMigrationFile(
            'create_users_table',
            "CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT);",
            "DROP TABLE users;"
        );

        $migrator = new Migrator($this->db, $this->tempMigrationsDir);
        $migrator->run(); // Executing Batch 1

        // Batch 2 Migration
        $m2 = $this->createUniqueDummyMigrationFile(
            'create_posts_table',
            "CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT);",
            "DROP TABLE posts;"
        );

        $migrator->run(); // Executing Batch 2

        // Rollback Batch 2
        $rolledBack = $migrator->rollback();
        $this->assertCount(1, $rolledBack);
        $this->assertEquals($m2['filename'], $rolledBack[0]);

        // Verify 'users' table remains, 'posts' table is dropped
        $stmtUsers = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name = 'users'");
        $usersResult = is_object($stmtUsers) && method_exists($stmtUsers, 'fetchAll') ? $stmtUsers->fetchAll() : (array) $stmtUsers;
        $this->assertNotEmpty($usersResult);

        $stmtPosts = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name = 'posts'");
        $postsResult = is_object($stmtPosts) && method_exists($stmtPosts, 'fetchAll') ? $stmtPosts->fetchAll() : (array) $stmtPosts;
        $this->assertEmpty($postsResult);
    }

    /**
     * Test that failed migrations trigger a database transaction rollback.
     */
    public function testFailedMigrationRollsBackTransaction(): void
    {
        $this->createUniqueDummyMigrationFile(
            'invalid_migration',
            "INVALID SQL SYNTAX HERE;",
            ""
        );

        $migrator = new Migrator($this->db, $this->tempMigrationsDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Migration failed on/');

        $migrator->run();
    }

    /**
     * Test the CLI MakeMigrationCommand file output generation.
     */
    public function testMakeMigrationCommandCreatesFile(): void
    {
        $command = new MakeMigrationCommand();
        
        // Execute make:migration create_orders_table
        $exitCode = $command->execute(['create_orders_table']);
        $this->assertEquals(0, $exitCode);

        $targetDir = getcwd() . '/database/migrations';
        $files = glob($targetDir . '/*_create_orders_table.php') ?: [];

        $this->assertNotEmpty($files);
        $filePath = $files[0];

        $this->assertFileExists($filePath);

        // Cleanup generated test file
        unlink($filePath);
    }

    /**
     * Helper method to generate unique migration filenames and class names dynamically per test run.
     *
     * @param int $offsetSeconds Allows artificial timestamp offset to guarantee chronological order.
     * @return array{filename: string, class: string, path: string}
     */
    private function createUniqueDummyMigrationFile(
        string $actionName,
        string $upSql,
        string $downSql,
        int $offsetSeconds = 0
    ): array {
        $uniqueId = substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
        $timestamp = date('Y_m_d_His', time() + $offsetSeconds);
        
        $filename = "m{$timestamp}_{$actionName}_{$uniqueId}";
        
        $className = implode('', array_map('ucfirst', explode('_', $filename)));
        $filePath = $this->tempMigrationsDir . '/' . $filename . '.php';

        $content = <<<PHP
<?php

declare(strict_types=1);

use Phue\Core\Database\Migration;

final class {$className} extends Migration
{
    public function up(): void
    {
        \$this->execute("{$upSql}");
    }

    public function down(): void
    {
        \$this->execute("{$downSql}");
    }
}
PHP;

        file_put_contents($filePath, $content);

        return [
            'filename' => $filename,
            'class' => $className,
            'path' => $filePath,
        ];
    }
}