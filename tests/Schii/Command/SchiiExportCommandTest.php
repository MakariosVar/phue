<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Command;

use PHPUnit\Framework\TestCase;
use Phue\Schii\Commands\SchiiExportCommand;
use Phue\Tests\Schii\Stubs\User;

/**
 * SchiiExportCommand: identity, stream output and exit-code contracts.
 *
 * @package Phue\Tests\Schii\Command
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class SchiiExportCommandTest extends TestCase
{
    private string $tmpDir;
    /** @var resource */
    private $stdout;
    /** @var resource */
    private $stderr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phue-export-' . bin2hex(random_bytes(6));
        $this->stdout = fopen('php://memory', 'w+');
        $this->stderr = fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*.php') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function read($stream): string
    {
        rewind($stream);
        return (string) stream_get_contents($stream);
    }

    public function testCommandIdentityContract(): void
    {
        $command = new SchiiExportCommand();

        $this->assertSame('schii:export', $command->getName());
        $this->assertSame('Exports Schii schema to standalone Phii files.', $command->getDescription());
    }

    public function testExecuteWithUnknownNamespaceReportsNoModelsAndSucceeds(): void
    {
        $command = new SchiiExportCommand();

        $code = $command->execute(
            ['--namespace=Phue\Tests\Schii\DoesNotExist', '--output=' . $this->tmpDir],
            $this->stdout,
            $this->stderr,
        );

        $this->assertSame(0, $code);
        $this->assertStringContainsString("No model classes found", $this->read($this->stdout));
        $this->assertSame('', $this->read($this->stderr));
    }

    public function testExecuteExportsExplicitFqcnAndWritesFile(): void
    {
        $command = new SchiiExportCommand();

        $code = $command->execute(
            [
                '--namespace=Phue\Tests\Schii\Stubs',
                '--output=' . $this->tmpDir,
                User::class,
            ],
            $this->stdout,
            $this->stderr,
        );

        $this->assertSame(0, $code);
        $this->assertStringContainsString("Exported schema for 'Phue\\Tests\\Schii\\Stubs\\User'", $this->read($this->stdout));
        $this->assertSame('', $this->read($this->stderr));
        $this->assertFileExists($this->tmpDir . '/user.php');
    }

    public function testExecuteDiscoversAllModelsWhenNoPositionalProvided(): void
    {
        $command = new SchiiExportCommand();

        $code = $command->execute(
            ['--namespace=Phue\Tests\Schii\Stubs', '--output=' . $this->tmpDir],
            $this->stdout,
            $this->stderr,
        );

        $this->assertSame(0, $code);
        $out = $this->read($this->stdout);
        $this->assertStringContainsString('User', $out);
        $this->assertStringContainsString('Profile', $out);
        $this->assertFileExists($this->tmpDir . '/user.php');
        $this->assertFileExists($this->tmpDir . '/profile.php');
    }

    public function testExecuteWarnsForUnknownPositionalModel(): void
    {
        $command = new SchiiExportCommand();

        $code = $command->execute(
            ['--namespace=Phue\Tests\Schii\Stubs', '--output=' . $this->tmpDir, 'Ghost'],
            $this->stdout,
            $this->stderr,
        );

        $this->assertSame(0, $code);
        $this->assertStringContainsString("Model class 'Phue\\Tests\\Schii\\Stubs\\Ghost' not found.", $this->read($this->stderr));
    }

    public function testExecuteHonorsModuleOptionInOutput(): void
    {
        $command = new SchiiExportCommand();

        $code = $command->execute(
            ['--namespace=Phue\Tests\Schii\Stubs', '--module=api', '--output=' . $this->tmpDir, User::class],
            $this->stdout,
            $this->stderr,
        );

        $this->assertSame(0, $code);
        $this->assertStringContainsString("(module: 'api')", $this->read($this->stdout));
    }
}