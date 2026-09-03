<?php

namespace Phue\Core\Console;

use RuntimeException;

/**
 * Core CLI Console Application runner and command registry.
 *
 * @package Phue\Core\Console
 */
class Application
{
    /**
     * Registered commands map indexed by command name.
     *
     * @var array<string, CommandInterface>
     */
    protected array $commands = [];

    /**
     * App version name.
     */
    protected string $version = '1.0.0';

    public function __construct(string $version = '1.0.0')
    {
        $this->version = $version;
    }

    /**
     * Registers a CLI command.
     */
    public function add(CommandInterface $command): static
    {
        $this->commands[$command->getName()] = $command;
        return $this;
    }

    /**
     * Runs the console application using input arguments.
     *
     * @param array<int, string> $argv
     */
    public function run(array $argv = [], $outputStream = null, $errorStream = null): int
    {
        if (empty($argv)) {
            $argv = $_SERVER['argv'] ?? [];
        }

        if (empty($outputStream)) {
            $outputStream = \STDOUT;        
        }

        if (empty($errorStream)) {
            $errorStream = \STDERR;
        }

        $commandName = $argv[1] ?? 'list';
        $args = array_slice($argv, 2);

        if ($commandName === 'list' || $commandName === '--help' || $commandName === '-h') {
            return $this->renderHelp($outputStream, $errorStream);
        }

        if (!isset($this->commands[$commandName])) {
            fwrite($errorStream, sprintf("\033[31mError: Command \"%s\" is not defined.\033[0m\n\n", $commandName));
            $this->renderHelp($outputStream, $errorStream);
            return 1;
        }

        try {
            return $this->commands[$commandName]->execute($args, $outputStream, $errorStream);
        } catch (\Throwable $e) {
            fwrite($errorStream, sprintf("\033[31mCommand failed: %s\033[0m\n", $e->getMessage()));
            return 1;
        }
    }

    /**
     * Renders available commands and help menu.
     *
     * @param resource $outputStream
     * @param resource $errorStream
     * @return int
     */
    protected function renderHelp($outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        fwrite($outputStream, sprintf("\033[32mPhue Framework CLI Tool\033[0m v%s\n\n", $this->version));
        fwrite($outputStream, "\033[33mUsage:\033[0m\n");
        fwrite($outputStream, "  ./bin/phue <command> [options] [arguments]\n\n");
        fwrite($outputStream, "\033[33mAvailable Commands:\033[0m\n");

        foreach ($this->commands as $name => $command) {
            fwrite($outputStream, sprintf("  \033[32m%-22s\033[0m %s\n", $name, $command->getDescription()));
        }

        fwrite($outputStream, "\n");
        return 0;
    }

}