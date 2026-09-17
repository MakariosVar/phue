<?php

namespace Phue\Core\Console;

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
     * @var array<string, BaseCommandInterface>
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
    public function add(BaseCommandInterface $command): static
    {
        $this->commands[$command->getName()] = $command;
        return $this;
    }

    /**
     * Runs the console application using input arguments.
     *
     * Supports sub-commands via `command:sub` syntax for commands implementing
     * {@see CommandInterface} (convention-based routing).
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

        // Check if full command name (including colon) exists as registered command first
        // This allows commands like "module:new" to work without being parsed as sub-commands
        $subCommand = null;
        if (!isset($this->commands[$commandName]) && str_contains($commandName, ':')) {
            [$commandName, $subCommand] = explode(':', $commandName, 2);
        }

        if ($commandName === 'list' || $commandName === '--help' || $commandName === '-h') {
            return $this->renderHelp($outputStream, $errorStream);
        }

        if (!isset($this->commands[$commandName])) {
            fwrite($errorStream, sprintf("\033[31mError: Command \"%s\" is not defined.\033[0m\n\n", $commandName));
            $this->renderHelp($outputStream, $errorStream);
            return 1;
        }

        $command = $this->commands[$commandName];

        // If sub-command provided, prepend it to args for CommandInterface routing
        if ($subCommand !== null && $command instanceof \Phue\Core\Console\CommandInterface) {
            array_unshift($args, $subCommand);
        }

        try {
            return $command->execute($args, $outputStream, $errorStream);
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
        fwrite($outputStream, "  ./phue <command> [options] [arguments]\n\n");
        fwrite($outputStream, "\033[33mAvailable Commands:\033[0m\n");

        // Group command by their group attribute
        $groupped = [];
        foreach ($this->commands as $name => $command) {
            $group = $command->getGroup();
            if (!isset($groupped[$group])) {
                $groupped[$group] = [];
            }
            $groupped[$group][$name] = $command;
        }

        // Sort groups: "General" first, then alphabetical
        ksort($groupped);
        if (isset($groupped['General'])) {
            $general = $groupped['General'];
            unset($groupped['General']);
            $groupped = ['General' => $general] + $groupped;
        }

        foreach ($groupped as $groupName => $commands) {
            fwrite($outputStream, "\003[1m{$groupName}:\003[0m\n");

            foreach ($commands as $name => $command) {
                fwrite($outputStream, sprintf("  \033[32m%-22s\033[0m %s\n", $name, $command->getDescription()));

                if ($command instanceof CommandInterface && method_exists($command, 'getSubCommands')) {
                    $subCommands = $command->getSubCommands();
                    foreach ($subCommands as $subName => $subDescription) {
                        fwrite($outputStream, sprintf("  \033[36m%-22s\033[0m %s\n", "  {$name}:{$subName}", $subDescription));
                    }
                }
            }
        }

        fwrite($outputStream, "\n");
        return 0;
    }

}