<?php

namespace Phue\Tests\Core\Console;

use PHPUnit\Framework\TestCase;
use Phue\Core\Console\Commands\MakeControllerCommand;
use Phue\Core\Console\Commands\MakeMigrationCommand;
use Phue\Core\Console\Commands\MakeModelCommand;
use Phue\Core\Console\Commands\ServeCommand;

#[\PHPUnit\Framework\Attributes\Group('integration')]
class CommandsTest extends TestCase
{
    private string $appDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appDir = getcwd() . '/App';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (file_exists($this->appDir . '/Controllers/TestController.php')) {
            unlink($this->appDir . '/Controllers/TestController.php');
        }
        if (file_exists($this->appDir . '/Models/Article.php')) {
            unlink($this->appDir . '/Models/Article.php');
        }
        $dbDir = getcwd() . '/database/migrations';
        if (is_dir($dbDir)) {
            foreach (glob($dbDir . '/*.php') ?: [] as $f) { unlink($f); }
            rmdir($dbDir);
            rmdir(dirname($dbDir));
        }
    }

    public function testMakeControllerGeneratesFile(): void
    {
        $cmd = new MakeControllerCommand();

        ob_start();
        $code = $cmd->execute(['Test']);
        $output = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertFileExists($this->appDir . '/Controllers/TestController.php');
        $this->assertStringContainsString('Controller created successfully', $output);

        $content = file_get_contents($this->appDir . '/Controllers/TestController.php');
        $this->assertStringContainsString('class TestController', $content);
        $this->assertStringContainsString('use Phue\Core\Routing\Attribute\Route;', $content);
    }

    public function testMakeControllerFailsWhenNameMissing(): void
    {
        $cmd = new MakeControllerCommand();
        $errStream = fopen('php://memory', 'w+');

        $code = $cmd->execute([], \STDOUT, $errStream);

        fclose($errStream);
        $this->assertSame(1, $code);
    }


    public function testMakeControllerFailsIfAlreadyExists(): void
    {
        $cmd = new MakeControllerCommand();

        ob_start();
        $cmd->execute(['Test']);
        ob_end_clean();

        ob_start();
        $code = $cmd->execute(['Test']);
        ob_end_clean();

        $this->assertSame(1, $code);
    }

    public function testMakeModelGeneratesFile(): void
    {
        $cmd = new MakeModelCommand();

        ob_start();
        $code = $cmd->execute(['Article']);
        $output = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertFileExists($this->appDir . '/Models/Article.php');
        $this->assertStringContainsString('Model created successfully', $output);

        $content = file_get_contents($this->appDir . '/Models/Article.php');
        $this->assertStringContainsString('class Article extends BaseActiveRecord', $content);
        $this->assertStringContainsString("protected string \$table = 'articles';", $content);
    }

    public function testMakeModelFailsWhenNameMissing(): void
    {
        $cmd = new MakeModelCommand();
        $errStream = fopen('php://memory', 'w+');
        $code = $cmd->execute([], \STDOUT, $errStream);
        fclose($errStream);
        $this->assertSame(1, $code);
    }

    public function testMakeMigrationCommandMissingName(): void
    {
        $cmd = new MakeMigrationCommand();
        $errStream = fopen('php://memory', 'w+');
        $code = $cmd->execute([], \STDOUT, $errStream);
        fclose($errStream);
        $this->assertSame(1, $code);
    }

    public function testMakeModelFailsIfAlreadyExists(): void
    {
        $cmd = new MakeModelCommand();
        $errStream = fopen('php://memory', 'w+');
        ob_start();
        $cmd->execute(['Article']);
        ob_end_clean();
        $code = $cmd->execute(['Article'], \STDOUT, $errStream);
        fclose($errStream);
        $this->assertSame(1, $code);
    }

    public function testMakeMigrationCommandCreatesFile(): void
    {
        $cmd = new MakeMigrationCommand();
        $out = fopen('php://memory', 'r+');
        $code = $cmd->execute(['create_users_table'], $out);
        rewind($out);
        $output = stream_get_contents($out);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Created Migration:', $output);
        $files = glob(getcwd() . '/database/migrations/*.php');
        $this->assertNotEmpty($files);
    }

    public function testServeCommandMetadata(): void
    {
        $cmd = new ServeCommand();
        $this->assertSame('serve', $cmd->getName());
        $this->assertNotEmpty($cmd->getDescription());
    }
}