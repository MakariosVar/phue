<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Console;

use Phue\Core\Console\Application;
use Phue\Core\Console\BaseCommand;
use Phue\Core\Console\BaseCommandInterface;
use Phue\Core\Console\CommandInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the new sub-command CLI architecture:
 * - BaseCommandInterface (for full control commands)
 * - CommandInterface (for convention-based sub-commands)
 * - BaseCommand (abstract base with auto-routing)
 * - Application sub-command parsing and routing
 *
 * @package Phue\Tests\Core\Console
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class SubCommandArchitectureTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application('test');
    }

    // --- Test doubles ---

    private function createTraditionalCommand(): BaseCommandInterface
    {
        return new class implements \Phue\Core\Console\BaseCommandInterface {
            public function getName(): string { return 'traditional'; }
            public function getDescription(): string { return 'Traditional command'; }
            public function getGroup(): string { return 'General'; }
            public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int {
                fwrite($outputStream, "Traditional executed with: " . implode(' ', $args) . "\n");
                return self::SUCCESS;
            }
        };
    }

    private function createConventionCommand(): BaseCommand
    {
        return new class extends \Phue\Core\Console\BaseCommand {
            public function getName(): string { return 'convention'; }
            public function getDescription(): string { return 'Convention-based command'; }
            
            /**
             * @description First sub-command
             */
            public function first(array $args, $out, $err): int {
                fwrite($out, "First called with: " . implode(' ', $args) . "\n");
                return self::SUCCESS;
            }

            /**
             * @description Second sub-command
             */
            public function second(array $args, $out, $err): int {
                fwrite($err, "Second called\n");
                return self::SUCCESS;
            }
        };
    }

    // --- Tests ---

    public function testTraditionalCommandExecutesDirectly(): void
    {
        $command = new class implements \Phue\Core\Console\BaseCommandInterface {
            public function getName(): string { return 'traditional'; }
            public function getDescription(): string { return 'Traditional command'; }
            public function getGroup(): string { return 'General'; }
            public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int {
                fwrite($outputStream, "Traditional executed with: " . implode(' ', $args) . "\n");
                return self::SUCCESS;
            }
        };

        $app = new Application();
        $app->add($command);

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');

        $code = $app->run(['phue', 'traditional', 'arg1', 'arg2'], $out, $err);

        $this->assertSame(0, $code);
        rewind($out);
        $output = stream_get_contents($out);
        $this->assertStringContainsString('Traditional executed with: arg1 arg2', $output);
    }

    public function testConventionCommandRoutesToSubCommand(): void
    {
        $command = new class extends \Phue\Core\Console\BaseCommand {
            public function getName(): string { return 'convention'; }
            public function getDescription(): string { return 'Convention-based command'; }
            
            /**
             * @description First sub-command
             */
            public function first(array $args, $out, $err): int {
                fwrite($out, "First called with: " . implode(' ', $args) . "\n");
                return self::SUCCESS;
            }

            /**
             * @description Second sub-command
             */
            public function second(array $args, $out, $err): int {
                fwrite($err, "Second called\n");
                return self::SUCCESS;
            }
        };

        $app = new Application();
        $app->add($command);

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');

        $code = $app->run(['phue', 'convention:first', 'arg1'], $out, $err);

        $this->assertSame(0, $code);
        rewind($out);
        $output = stream_get_contents($out);
        $this->assertStringContainsString('First called with: arg1', $output);
    }

    public function testConventionCommandSecondSubCommand(): void
    {
        $command = new class extends \Phue\Core\Console\BaseCommand {
            public function getName(): string { return 'convention'; }
            public function getDescription(): string { return 'Convention-based command'; }
            
            /**
             * @description First sub-command
             */
            public function first(array $args, $out, $err): int {
                fwrite($out, "First called with: " . implode(' ', $args) . "\n");
                return self::SUCCESS;
            }

            /**
             * @description Second sub-command
             */
            public function second(array $args, $out, $err): int {
                fwrite($err, "Second called\n");
                return self::SUCCESS;
            }
        };

        $app = new Application();
        $app->add($command);

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');

        $code = $app->run(['phue', 'convention:second'], $out, $err);

        $this->assertSame(0, $code);
        rewind($err);
        $errorOutput = stream_get_contents($err);
        $this->assertStringContainsString('Second called', $errorOutput);
    }

    public function testConventionCommandWithoutSubCommandShowsHelp(): void
    {
        $command = new class extends \Phue\Core\Console\BaseCommand {
            public function getName(): string { return 'convention'; }
            public function getDescription(): string { return 'Convention-based command'; }
            
            /**
             * @description First sub-command
             */
            public function first(array $args, $out, $err): int {
                fwrite($out, "First called with: " . implode(' ', $args) . "\n");
                return self::SUCCESS;
            }

            /**
             * @description Second sub-command
             */
            public function second(array $args, $out, $err): int {
                fwrite($err, "Second called\n");
                return self::SUCCESS;
            }
        };

        $app = new Application();
        $app->add($command);

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');

        $code = $app->run(['phue', 'convention'], $out, $err);

        $this->assertSame(0, $code);
        rewind($out);
        $output = stream_get_contents($out);
        $this->assertStringContainsString('Available sub-commands', $output);
        $this->assertStringContainsString('first', $output);
        $this->assertStringContainsString('second', $output);
    }

    public function testInvalidSubCommandShowsErrorAndHelp(): void
    {
        $command = new class extends \Phue\Core\Console\BaseCommand {
            public function getName(): string { return 'convention'; }
            public function getDescription(): string { return 'Convention-based command'; }
            
            /**
             * @description First sub-command
             */
            public function first(array $args, $out, $err): int {
                fwrite($out, "First called with: " . implode(' ', $args) . "\n");
                return self::SUCCESS;
            }

            /**
             * @description Second sub-command
             */
            public function second(array $args, $out, $err): int {
                fwrite($err, "Second called\n");
                return self::SUCCESS;
            }
        };

        $app = new Application();
        $app->add($command);

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');

        $code = $app->run(['phue', 'convention:invalid'], $out, $err);

        $this->assertSame(1, $code);
        rewind($err);
        $error = stream_get_contents($err);
        $this->assertStringContainsString('Sub-command "invalid" not found', $error);
        $this->assertStringContainsString('Available sub-commands', $error);
    }

    public function testColonCommandsStillWorkWhenRegistered(): void
    {
        // Command with colon in name should work if registered
        $app = new Application();
        
        $command = new class implements \Phue\Core\Console\BaseCommandInterface {
            public function getName(): string { return 'module:new'; }
            public function getDescription(): string { return 'Create module'; }
            public function getGroup(): string { return 'Build'; }
            public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int {
                fwrite($outputStream, "Module new with: " . implode(' ', $args) . "\n");
                return self::SUCCESS;
            }
        };
        $app->add($command);

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');

        // Should execute as full command name, not parsed as sub-command
        $code = $app->run(['phue', 'module:new', 'MyModule'], $out, $err);

        $this->assertSame(0, $code);
        rewind($out);
        $output = stream_get_contents($out);
        $this->assertStringContainsString('Module new with: MyModule', $output);
    }

    public function testSubCommandParsingWithColonSyntax(): void
    {
        $command = new class extends \Phue\Core\Console\BaseCommand {
            public function getName(): string { return 'convention'; }
            public function getDescription(): string { return 'Convention-based command'; }
            
            /**
             * @description First sub-command
             */
            public function first(array $args, $out, $err): int {
                fwrite($out, "First called with: " . implode(' ', $args) . "\n");
                return self::SUCCESS;
            }

            /**
             * @description Second sub-command
             */
            public function second(array $args, $out, $err): int {
                fwrite($err, "Second called\n");
                return self::SUCCESS;
            }
        };

        $app = new Application();
        $app->add($command);

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');

        // Test convention:first with additional args
        $code = $app->run(['phue', 'convention:first', 'arg1', 'arg2'], $out, $err);

        $this->assertSame(0, $code);
        rewind($out);
        $output = stream_get_contents($out);
        $this->assertStringContainsString('First called with: arg1 arg2', $output);
    }

    public function testNonExistentCommandShowsError(): void
    {
        $app = new Application();

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');

        $code = $app->run(['phue', 'nonexistent'], $out, $err);

        $this->assertSame(1, $code);
        rewind($err);
        $error = stream_get_contents($err);
        $this->assertStringContainsString('Command "nonexistent" is not defined', $error);
    }

    public function testHelpShowsSubCommandsForConventionCommands(): void
    {
        $command = new class extends \Phue\Core\Console\BaseCommand {
            public function getName(): string { return 'convention'; }
            public function getDescription(): string { return 'Convention-based command'; }
            
            /**
             * @description First sub-command
             */
            public function first(array $args, $out, $err): int {
                fwrite($out, "First called with: " . implode(' ', $args) . "\n");
                return self::SUCCESS;
            }

            /**
             * @description Second sub-command
             */
            public function second(array $args, $out, $err): int {
                fwrite($err, "Second called\n");
                return self::SUCCESS;
            }
        };

        $app = new Application();
        $app->add($command);

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');

        $code = $app->run(['phue', '--help'], $out, $err);

        $this->assertSame(0, $code);
        rewind($out);
        $output = stream_get_contents($out);
        $this->assertStringContainsString('convention:first', $output);
        $this->assertStringContainsString('convention:second', $output);
        $this->assertStringContainsString('First sub-command', $output);
        $this->assertStringContainsString('Second sub-command', $output);
    }

    public function testSubCommandMethodVisibility(): void
    {
        $app = new Application();
        
        // Command with private/protected methods that should NOT be exposed
        $command = new class extends \Phue\Core\Console\BaseCommand {
            public function getName(): string { return 'visibility'; }
            public function getDescription(): string { return 'Test visibility'; }
            
            public function publicMethod(): int { return 0; }
            protected function protectedMethod(): int { return 0; }
            private function privateMethod(): int { return 0; }
        };
        $app->add($command);

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');

        // Public method should work
        $code = $app->run(['phue', 'visibility:publicMethod'], $out, $err);
        $this->assertSame(0, $code);

        // Protected method should fail
        $out2 = fopen('php://memory', 'w+');
        $err2 = fopen('php://memory', 'w+');
        $code2 = $app->run(['phue', 'visibility:protectedMethod'], $out2, $err2);
        $this->assertSame(1, $code2);
        rewind($err2);
        $this->assertStringContainsString('not accessible', stream_get_contents($err2));

        // Private method should fail
        $out3 = fopen('php://memory', 'w+');
        $err3 = fopen('php://memory', 'w+');
        $code3 = $app->run(['phue', 'visibility:privateMethod'], $out3, $err3);
        $this->assertSame(1, $code3);
    }

    public function testSubCommandDescriptionFromDocblock(): void
    {
        $command = new class extends \Phue\Core\Console\BaseCommand {
            public function getName(): string { return 'doc'; }
            public function getDescription(): string { return 'Test doc'; }
            
            /**
             * @description Custom description from docblock
             */
            public function customDesc(): int { return 0; }
            
            public function noDesc(): int { return 0; }
        };
        
        $app = new Application();
        $app->add($command);

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $app->run(['phue', '--help'], $out, $err);

        rewind($out);
        $output = stream_get_contents($out);
        $this->assertStringContainsString('Custom description from docblock', $output);
        $this->assertStringContainsString('No description', $output);
    }

    public function testHelpShowsGroupedCommands(): void
    {
        $generalCmd = new class implements BaseCommandInterface {
            public function getName(): string { return 'serve'; }
            public function getDescription(): string { return 'Serve the app'; }
            public function getGroup(): string { return 'General'; }
            public function execute(array $args = [], $outputStream = \STDOUT, $errorStream = \STDERR): int { return 0; }
        };

        $buildCmd = new class extends BaseCommand {
            public function getName(): string { return 'module'; }
            public function getDescription(): string { return 'Module management'; }
            protected string $group = 'Build';
            public function execute(array $args = [], $outputStream = \STDOUT, $errorStream = \STDERR): int { return 0; }
        };

        $devCmd = new class extends BaseCommand {
            public function getName(): string { return 'cache:clear'; }
            public function getDescription(): string { return 'Clear cache'; }
            protected string $group = 'Dev';
            public function execute(array $args = [], $outputStream = \STDOUT, $errorStream = \STDERR): int { return 0; }
        };

        $app = new Application();
        $app->add($generalCmd);
        $app->add($buildCmd);
        $app->add($devCmd);

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $app->run(['phue', '--help'], $out, $err);

        rewind($out);
        $output = stream_get_contents($out);

        $this->assertStringContainsString('General:', $output);
        $this->assertStringContainsString('Build:', $output);
        $this->assertStringContainsString('Dev:', $output);
        $this->assertStringContainsString('serve', $output);
        $this->assertStringContainsString('module', $output);
        $this->assertStringContainsString('cache:clear', $output);

        $generalPos = strpos($output, 'General:');
        $buildPos = strpos($output, 'Build:');
        $devPos = strpos($output, 'Dev:');
        $this->assertLessThan($buildPos, $generalPos, 'General group should come before Build');
        $this->assertLessThan($devPos, $generalPos, 'General group should come before Dev');
        $this->assertLessThan($devPos, $buildPos, 'Build group should come before Dev');
    }
}