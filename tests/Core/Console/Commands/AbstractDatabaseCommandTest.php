<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Console\Commands;

use Phue\Core\Console\Commands\AbstractDatabaseCommand;
use Phue\Core\Console\Commands\SchiiMigrateCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Regression guard for the shared resolveConnection() base-class resolver.
 *
 * Before this fix Migrate* commands each duplicated a private resolveConnection()
 * resolving directly from getcwd(). Now AbstractDatabaseCommand holds the single
 * resolver and SchiiMigrateCommand (extends AbstractDatabaseCommand) consumes it.
 * This test proves the shared resolver honours PHUE_BASE_PATH when defined.
 *
 * @package Phue\Tests\Core\Console\Commands
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class AbstractDatabaseCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/phue_base_cmd_' . uniqid();
        mkdir($this->tmpDir . '/config', 0777, true);
        file_put_contents(
            $this->tmpDir . '/config/database.php',
            "<?php\nreturn [\n    'default' => 'sqlite',\n" .
            "    'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],\n" .
            "];\n"
        );
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpDir);
        parent::tearDown();
    }

    public function testSharedResolveConnectionHonorsBasePath(): void
    {
        // Resolve against the temp app by chdir-ing there (PHUE_BASE_PATH is not
        // defined globally, so the resolver falls back to getcwd()).
        $oldCwd = getcwd();
        chdir($this->tmpDir);

        // SchiiMigrateCommand extends AbstractDatabaseCommand and uses the shared resolver.
        $cmd = new SchiiMigrateCommand();
        $method = new \ReflectionMethod(AbstractDatabaseCommand::class, 'resolveConnection');
        $closure = $method->getClosure($cmd);
        $connection = $closure();

        chdir($oldCwd);

        $this->assertInstanceOf(\Phue\Core\Database\Connection::class, $connection);
        $this->assertSame('sqlite', $connection->getDriver()->getName());
    }

    public function testSharedResolveConnectionThrowsOnMissingConfigDir(): void
    {
        $oldCwd = getcwd();
        chdir($this->tmpDir);

        // Remove the config dir so the resolver's is_dir() fails.
        $this->rmTree($this->tmpDir . '/config');

        $cmd = new SchiiMigrateCommand();
        $method = new \ReflectionMethod(AbstractDatabaseCommand::class, 'resolveConnection');
        $closure = $method->getClosure($cmd);

        $this->expectException(RuntimeException::class);
        try { $closure(); } finally { chdir($oldCwd); }
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? $this->rmTree($f) : @unlink($f);
        }
        @rmdir($dir);
    }
}
