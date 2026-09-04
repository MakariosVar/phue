<?php

namespace Phue\Tests\Core\Console;

use PHPUnit\Framework\TestCase;
use Phue\Core\Console\Application;
use Phue\Core\Console\CommandInterface;

class DummyCommand implements CommandInterface
{
    public function getName(): string
    {
        return 'test:dummy';
    }

    public function getDescription(): string
    {
        return 'Runs a dummy test command.';
    }

    public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        fwrite($outputStream, "Executed dummy command");
        return 0;
    }
}

#[\PHPUnit\Framework\Attributes\Group('integration')]
class ConsoleApplicationTest extends TestCase
{
    public function testRegistersAndExecutesCommand(): void
    {
        $app = new Application('1.0.0');
        $app->add(new DummyCommand());

        $outStream = fopen('php://memory', 'w+');
        $exitCode = $app->run(['bin/phue', 'test:dummy'], $outStream);

        rewind($outStream);
        $output = stream_get_contents($outStream);
        fclose($outStream);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Executed dummy command', $output);
    }

    public function testRendersHelpAndCommandList(): void
    {
        $app = new Application('1.0.0');
        $app->add(new DummyCommand());

        $outStream = fopen('php://memory', 'w+');
        $exitCode = $app->run(['bin/phue', 'list'], $outStream);

        rewind($outStream);
        $output = stream_get_contents($outStream);
        fclose($outStream);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Phue Framework CLI Tool', $output);
        $this->assertStringContainsString('v1.0.0', $output);
        $this->assertStringContainsString('test:dummy', $output);
        $this->assertStringContainsString('Runs a dummy test command.', $output);
    }


    public function testReturnsErrorForUnknownCommand(): void
    {
        $app = new Application();

        $outStream = fopen('php://memory', 'w+');
        $errStream = fopen('php://memory', 'w+');

        $exitCode = $app->run(['bin/phue', 'unknown:command'], $outStream, $errStream);

        fclose($outStream);
        fclose($errStream);

        $this->assertSame(1, $exitCode);
    }

}