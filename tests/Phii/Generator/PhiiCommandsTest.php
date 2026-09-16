<?php

declare(strict_types=1);

namespace Phue\Tests\Phii\Generator;

use Phue\Core\Console\CommandInterface;
use Phue\Modules\Phii\Generator\Commands\ControllerGeneratorCommand;
use Phue\Modules\Phii\Generator\Commands\CrudGeneratorCommand;
use Phue\Modules\Phii\Generator\Commands\MigrationGeneratorCommand;
use Phue\Modules\Phii\Generator\Commands\ModelGeneratorCommand;
use Phue\Modules\Phii\Generator\Commands\ModuleGeneratorCommand;
use Phue\Modules\Schii\Schema\SchemaRegistry;
use Phue\Tests\Schii\Stubs\Profile;
use PHPUnit\Framework\TestCase;

/**
 * Phii console commands: they must resolve a real model, generate real,
 * parseable artifacts and return CommandInterface exit codes.
 *
 * @package Phue\Tests\Phii\Generator
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class PhiiCommandsTest extends TestCase
{
    private string $dump;
    private SchemaRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dump = sys_get_temp_dir() . '/phue_phii_cmd_' . uniqid();
        mkdir($this->dump, 0777, true);
        $this->registry = new SchemaRegistry();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dump)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dump, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() && !$item->isLink()
                    ? rmdir($item->getPathname())
                    : unlink($item->getPathname());
            }
            rmdir($this->dump);
        }

        parent::tearDown();
    }

    /**
     * Assert that a generated PHP artifact is syntactically valid.
     */
    private function assertPhpIsValid(string $file): void
    {
        $output = [];
        $exit = 0;
        exec(PHP_BINARY . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, implode("\n", $output));
    }

    /**
     * @return array{code: int, out: string, err: string}
     */
    private function runCommand(CommandInterface $command, array $args): array
    {
        $out = fopen('php://temp', 'r+');
        $err = fopen('php://temp', 'r+');

        $code = $command->execute($args, $out, $err);

        rewind($out);
        rewind($err);

        return [
            'code' => $code,
            'out' => (string) stream_get_contents($out),
            'err' => (string) stream_get_contents($err),
        ];
    }

    /**
     * Snapshot of the real application tree that generators must never touch.
     *
     * @return array<int, string> Sorted relative paths under App/Models, App/Modules and App/Controllers.
     */
    private function appTreeSnapshot(): array
    {
        $app = dirname(__DIR__, 3) . '/App';
        $files = [];

        foreach (['Models', 'Modules', 'Controllers'] as $dir) {
            $base = "{$app}/{$dir}";
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $entry) {
                $files[] = "{$dir}/" . substr($entry->getPathname(), strlen($app) + 1);
            }
        }

        sort($files);

        return $files;
    }

    public function testAllCommandsExposeRegisteredNames(): void
    {
        $this->assertSame('generate:model', (new ModelGeneratorCommand())->getName());
        $this->assertSame('generate:controller', (new ControllerGeneratorCommand())->getName());
        $this->assertSame('generate:migration', (new MigrationGeneratorCommand())->getName());
        $this->assertSame('generate:crud', (new CrudGeneratorCommand())->getName());
        $this->assertSame('generate:module', (new ModuleGeneratorCommand())->getName());
    }

    public function testModelCommandGeneratesActiveRecordFromSchema(): void
    {
        $command = new ModelGeneratorCommand(generator: null, schemaRegistry: $this->registry);
        $result = $this->runCommand($command, [Profile::class, '--output=' . $this->dump, '--type=active-record']);

        $this->assertSame(CommandInterface::SUCCESS, $result['code'], $result['err']);
        $this->assertStringContainsString('Generated model at:', $result['out']);
        $this->assertStringContainsString($this->dump, $result['out']);
        $this->assertFileExists($this->dump . '/App/Models/Profile.php');
    }

    public function testModelCommandRejectsMissingModelArgument(): void
    {
        $command = new ModelGeneratorCommand(generator: null, schemaRegistry: $this->registry);
        $result = $this->runCommand($command, []);

        $this->assertSame(CommandInterface::FAILURE, $result['code']);
        $this->assertStringContainsString('Missing positional argument', $result['err']);
    }

    public function testControllerCommandGeneratesController(): void
    {
        $command = new ControllerGeneratorCommand();
        $result = $this->runCommand($command, [Profile::class, '--output=' . $this->dump, '--module=admin']);

        $this->assertSame(CommandInterface::SUCCESS, $result['code'], $result['err']);
        $this->assertStringContainsString('ProfileController', $result['out']);
        $this->assertFileExists($this->dump . '/App/Modules/admin/Controller/ProfileController.php');
        $this->assertPhpIsValid($this->dump . '/App/Modules/admin/Controller/ProfileController.php');
    }

    public function testMigrationCommandGeneratesMigration(): void
    {
        $command = new MigrationGeneratorCommand(generator: null, schemaRegistry: $this->registry);
        $result = $this->runCommand($command, [Profile::class, '--output=' . $this->dump, '--driver=sqlite']);

        $this->assertSame(CommandInterface::SUCCESS, $result['code'], $result['err']);

        $created = glob($this->dump . '/database/migrations/*_create_profiles.php');
        $this->assertNotEmpty($created, 'Expected a *_create_profiles.php artifact in ' . $this->dump . '/database/migrations');
        $this->assertPhpIsValid($created[0]);
    }

    public function testCrudCommandGeneratesAllArtifacts(): void
    {
        $command = new CrudGeneratorCommand(generator: null, schemaRegistry: $this->registry);
        $leftovers = $this->appTreeSnapshot();
        $result = $this->runCommand($command, [Profile::class, '--output=' . $this->dump, '--module=admin']);

        $this->assertSame(CommandInterface::SUCCESS, $result['code'], $result['err']);

        $expected = [
            'model' => $this->dump . '/App/Models/Profile.php',
            'controller' => $this->dump . '/App/Modules/admin/Controller/ProfileController.php',
        ];
        foreach ($expected as $artifact) {
            $this->assertFileExists($artifact);
            $this->assertPhpIsValid($artifact);
        }

        $this->assertNotEmpty(
            glob($this->dump . '/database/migrations/*_create_profiles.php'),
            'The CRUD command must scaffold a migration under {--output}/database/migrations.'
        );

        $this->assertStringContainsString($this->dump, $result['out']);

        $this->assertSame(
            $leftovers,
            $this->appTreeSnapshot(),
            'The CRUD command must never write outside --output (App/Models, App/Modules, App/Controllers must stay untouched).'
        );
    }

    public function testModuleCommandGeneratesModuleSkeleton(): void
    {
        $command = new ModuleGeneratorCommand();
        $result = $this->runCommand($command, ['Blog', '--output=' . $this->dump]);

        $this->assertSame(CommandInterface::SUCCESS, $result['code'], $result['err']);
        $this->assertDirectoryExists($this->dump . '/App/Modules/Blog/Config');
        $this->assertFileExists($this->dump . '/App/Modules/Blog/Config/module.php');
    }
}