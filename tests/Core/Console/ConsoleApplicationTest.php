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

    public function execute(array $args): int
    {
        echo "Executed dummy command";
        return 0;
    }
}

class ConsoleApplicationTest extends TestCase
{
    public function testRegistersAndExecutesCommand(): void
    {
        $app = new Application('1.0.0');
        $app->add(new DummyCommand());

        ob_start();
        $exitCode = $app->run(['bin/phue', 'test:dummy']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Executed dummy command', $output);
    }

    public function testRendersHelpAndCommandList(): void
    {
        $app = new Application('1.0.0');
        $app->add(new DummyCommand());

        ob_start();
        $exitCode = $app->run(['bin/phue', 'list']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Phue Framework CLI Tool', $output);
        $this->assertStringContainsString('v1.0.0', $output);
        $this->assertStringContainsString('test:dummy', $output);
        $this->assertStringContainsString('Runs a dummy test command.', $output);
    }

    public function testReturnsErrorForUnknownCommand(): void
    {
        $app = new Application();

        // Silence STDERR output during tests
        ob_start();
        $exitCode = $app->run(['bin/phue', 'unknown:command']);
        ob_end_clean();

        $this->assertSame(1, $exitCode);
    }
}