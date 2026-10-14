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
            @rmdir($dbDir);
        }
    }

    public function testMakeControllerGeneratesFile(): void
    {
        $cmd = new MakeControllerCommand();

        $out = fopen('php://memory', 'w+');
        $code = $cmd->execute(['Test'], $out);
        rewind($out);
        $output = stream_get_contents($out);

        $this->assertSame(0, $code);
        $this->assertFileExists($this->appDir . '/Controllers/TestController.php');
        $this->assertStringContainsString('Controller created successfully', $output);

        $content = file_get_contents($this->appDir . '/Controllers/TestController.php');
        $this->assertStringContainsString('class TestController', $content);
        $this->assertStringContainsString('use Phue\Core\Routing\Attribute\Route;', $content);
        // Route(path, methods) positional order — never path='GET' (which made the route unreachable)
        $this->assertStringContainsString("#[Route('/', ['GET'])]", $content);
        $this->assertStringNotContainsString("Route('GET'", $content);
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

        // Streams, not the STDOUT/STDERR defaults: fwrite() to those bypasses
        // ob_start()/ob_end_clean(), so the command's "already exists" error
        // used to leak to the console and land after the runner's summary on
        // every full-suite run — noise that taught readers to ignore the tail.
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');

        $cmd->execute(['Test'], $out, $err);          // first call creates the file
        $code = $cmd->execute(['Test'], $out, $err);  // second must refuse

        rewind($err);
        $errorOutput = stream_get_contents($err);
        fclose($out);
        fclose($err);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Controller TestController already exists', $errorOutput);
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
        $this->assertSame('serve:php', $cmd->getName());
        $this->assertNotEmpty($cmd->getDescription());
    }
}