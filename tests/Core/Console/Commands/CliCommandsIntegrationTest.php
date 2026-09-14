<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Console\Commands;

use PHPUnit\Framework\TestCase;
use Phue\Core\Console\Commands\DeployCommand;
use Phue\Core\Console\Commands\MigrateCommand;
use Phue\Core\Console\Commands\MigrateRollbackCommand;
use Phue\Core\Console\Commands\SchiiMigrateCommand;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use Phue\Schii\Attribute\Field;
use Phue\Schii\Attribute\UI;

/**
 * Integration tests executing the real CLI commands against an isolated
 * sqlite database + temporary migration/deploy directories.
 *
 * Context note: MigrateCommand / MigrateRollbackCommand / SchiiMigrateCommand
 * resolve their DB from getcwd(), so each test chdir()s into a clean temp dir
 * and restores the original working directory in tearDown().
 */
#[UI(label: 'CLI Widgets', icon: 'wrench', layout: 'list')]
class CliWidget extends BaseActiveRecord
{
    protected string $table = 'cli_widgets';

    #[Field(type: 'text', required: true, searchable: true)]
    public string $name = '';

    #[Field(type: 'number')]
    public float $weight = 0.0;
}

#[\PHPUnit\Framework\Attributes\Group('integration')]
final class CliCommandsIntegrationTest extends TestCase
{
    private string $origCwd;
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->origCwd = getcwd();
        $this->baseDir = sys_get_temp_dir() . '/phue_cli_' . uniqid('', true);
        mkdir($this->baseDir . '/database/migrations', 0777, true);
        mkdir($this->baseDir . '/config', 0777, true);
        file_put_contents(
            $this->baseDir . '/config/database.php',
            "<?php\nreturn [\n"
            . "    'default' => 'sqlite',\n"
            . "    'connections' => [\n"
            . "        'sqlite' => [\n"
            . "            'driver' => 'sqlite',\n"
            . "            'database' => " . var_export($this->baseDir . '/database/database.sqlite', true) . ",\n"
            . "        ],\n"
            . "    ],\n"
            . "];\n",
        );
    }

    protected function tearDown(): void
    {
        chdir($this->origCwd);
        if (is_dir($this->baseDir)) {
            $this->removeDir($this->baseDir);
        }
        parent::tearDown();
    }

    /**
     * @return array{out: string, err: string, code: int}
     */
    private function runCommand(object $command, array $args): array
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');

        $code = $command->execute($args, $out, $err);

        rewind($out);
        rewind($err);

        return [
            'out' => stream_get_contents($out),
            'err' => stream_get_contents($err),
            'code' => $code,
        ];
    }

    private function openFileDb(): Connection
    {
        return new Connection([
            'driver' => 'sqlite',
            'database' => $this->baseDir . '/database/database.sqlite',
        ]);
    }

    private function countTableRows(Connection $db, string $table): int
    {
        return count($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", [$table])->fetchAll());
    }

    /**
     * @return array<string, string> filename/classname map
     */
    private function createMigrationFile(string $upSql, string $downSql, int $offsetSeconds = 0): array
    {
        $uniqueId = substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
        $timestamp = date('Y_m_d_His', time() + $offsetSeconds);
        $filename = "m{$timestamp}_cli_{$uniqueId}";
        $className = implode('', array_map('ucfirst', explode('_', $filename)));

        file_put_contents(
            $this->baseDir . '/database/migrations/' . $filename . '.php',
            <<<PHP
<?php
use Phue\Core\Database\Migration;
final class {$className} extends Migration
{
    public function up(): void { \$this->execute("{$upSql}"); }
    public function down(): void { \$this->execute("{$downSql}"); }
}
PHP
        );

        return ['filename' => $filename, 'className' => $className];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $entry) {
            $entry->isDir() && !$entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }

        rmdir($dir);
    }

    // ------------------------------------------------------------------
    // migrate
    // ------------------------------------------------------------------

    public function testMigrateCommandAppliesPendingMigrations(): void
    {
        chdir($this->baseDir);
        $this->createMigrationFile(
            "CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT);",
            "DROP TABLE users;"
        );

        $result = $this->runCommand(new MigrateCommand(), []);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('Running pending migrations...', $result['out']);
        $this->assertStringContainsString('Migrated:', $result['out']);
        $this->assertStringNotContainsString('Nothing to migrate', $result['out']);
        $this->assertSame('', $result['err']);

        $this->assertSame(1, $this->countTableRows($this->openFileDb(), 'users'));
    }

    public function testMigrateCommandHandlesMigrationError(): void
    {
        chdir($this->baseDir);

        $brokenClass = 'BrokenMigration' . uniqid();
        file_put_contents(
            $this->baseDir . '/database/migrations/' . $brokenClass . '.php',
            '<?php use Phue\Core\Database\Migration; final class ' . $brokenClass . ' extends Migration { public function up(): void { throw new \RuntimeException("migration boom"); } public function down(): void {} }'
        );

        $result = $this->runCommand(new MigrateCommand(), []);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Migration error:', $result['err']);
        @unlink($this->baseDir . '/database/migrations/' . $brokenClass . '.php');
    }

    // ------------------------------------------------------------------
    // migrate:rollback
    // ------------------------------------------------------------------

    public function testMigrateRollbackCommandRollsBackLastBatch(): void
    {
        chdir($this->baseDir);
        $this->createMigrationFile(
            "CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT);",
            "DROP TABLE users;"
        );

        $migrate = $this->runCommand(new MigrateCommand(), []);
        $this->assertSame(0, $migrate['code']);
        $this->assertSame(1, $this->countTableRows($this->openFileDb(), 'users'));

        $rollback = $this->runCommand(new MigrateRollbackCommand(), []);

        $this->assertSame(0, $rollback['code']);
        $this->assertStringContainsString('Rolling back last migration batch...', $rollback['out']);
        $this->assertStringContainsString('Rolled back:', $rollback['out']);
        $this->assertSame('', $rollback['err']);

        $this->assertSame(0, $this->countTableRows($this->openFileDb(), 'users'));

        $empty = $this->runCommand(new MigrateRollbackCommand(), []);
        $this->assertSame(0, $empty['code']);
        $this->assertStringContainsString('Nothing to rollback.', $empty['out']);
    }

    // ------------------------------------------------------------------
    // migrate:rollback with error path
    // ------------------------------------------------------------------

    public function testMigrateRollbackCommandHandlesRollbackError(): void
    {
        chdir($this->baseDir);
        $brokenClass = 'BrokenRollback' . uniqid();
        file_put_contents(
            $this->baseDir . '/database/migrations/' . $brokenClass . '.php',
            '<?php use Phue\Core\Database\Migration; final class ' . $brokenClass . ' extends Migration { public function up(): void {} public function down(): void { throw new \RuntimeException("rollback boom"); } }'
        );

        // First apply the broken migration
        $migrate = $this->runCommand(new MigrateCommand(), []);
        $this->assertSame(0, $migrate['code']);

        // Rollback should catch the error
        $rollback = $this->runCommand(new MigrateRollbackCommand(), []);
        $this->assertSame(1, $rollback['code']);
        $this->assertStringContainsString('Rollback error:', $rollback['err']);

        @unlink($this->baseDir . '/database/migrations/' . $brokenClass . '.php');
    }

    // ------------------------------------------------------------------
    // schii:migrate
    // ------------------------------------------------------------------

    public function testSchiiMigrateCommandCreatesTableFromModel(): void
    {
        chdir($this->baseDir);

        $result = $this->runCommand(new SchiiMigrateCommand(), [CliWidget::class]);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS "cli_widgets"', $result['out']);
        $this->assertSame('', $result['err']);

        $this->assertSame(1, $this->countTableRows($this->openFileDb(), 'cli_widgets'));

        $second = $this->runCommand(new SchiiMigrateCommand(), [CliWidget::class]);
        $this->assertSame(0, $second['code']);
        $this->assertStringContainsString('Schema up to date', $second['out']);
    }

    public function testSchiiMigrateCommandRequiresModelArgument(): void
    {
        chdir($this->baseDir);

        $result = $this->runCommand(new SchiiMigrateCommand(), []);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Model class name is required', $result['err']);
    }

    public function testSchiiMigrateCommandRejectsUnknownModel(): void
    {
        chdir($this->baseDir);

        $result = $this->runCommand(new SchiiMigrateCommand(), ['App\\Models\\Missing']);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('not found', $result['err']);
    }

    // ------------------------------------------------------------------
    // deploy
    // ------------------------------------------------------------------

    public function testDeployCommandRejectsInvalidDirectory(): void
    {
        $result = $this->runCommand(new DeployCommand(), ['--dir=' . $this->baseDir . '/nope']);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Invalid base directory', $result['err']);
    }

    public function testDeployCommandCreatesReleaseFlipsSymlinkAndPrunes(): void
    {
        file_put_contents($this->baseDir . '/composer.json', "{}");
        file_put_contents($this->baseDir . '/dummy.txt', 'hello');

        // Pre-create 3 old releases (older mtimes) to exercise retention pruning
        mkdir($this->baseDir . '/releases', 0777, true);
        $now = time();
        for ($i = 1; $i <= 3; $i++) {
            $release = $this->baseDir . "/releases/old_{$i}";
            mkdir($release, 0777, true);
            touch($release, $now - (3600 * $i));
        }

        $result = $this->runCommand(new DeployCommand(), [
            '--dir=' . $this->baseDir,
            '--keep=1',
        ]);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('Starting deployment for directory', $result['out']);
        $this->assertStringContainsString('Deployment completed successfully!', $result['out']);
        $this->assertSame('', $result['err']);

        // Exactly one release survives (the freshly deployed one)
        $releases = glob($this->baseDir . '/releases/*', GLOB_ONLYDIR) ?: [];
        $this->assertCount(1, $releases);

        // current symlink atomically points to the surviving release
        $this->assertTrue(is_link($this->baseDir . '/current'));
        $this->assertSame(realpath($releases[0]), realpath($this->baseDir . '/current'));

        // Release content was copied
        $this->assertFileExists($releases[0] . '/dummy.txt');
    }
}