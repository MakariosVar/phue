<?php

namespace Phue\Tests\Core\Console;

use PHPUnit\Framework\TestCase;
use Phue\Core\Console\Commands\MakeControllerCommand;
use Phue\Core\Console\Commands\MakeModelCommand;
use Phue\Core\Console\Commands\ServeCommand;

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
        if (file_exists($this->appDir . '/Controllers/TestController.php')) {
            unlink($this->appDir . '/Controllers/TestController.php');
        }
        if (file_exists($this->appDir . '/Models/Article.php')) {
            unlink($this->appDir . '/Models/Article.php');
        }
        parent::tearDown();
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

    public function testServeCommandMetadata(): void
    {
        $cmd = new ServeCommand();
        $this->assertSame('serve', $cmd->getName());
        $this->assertNotEmpty($cmd->getDescription());
    }
}