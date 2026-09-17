<?php

declare(strict_types=1);

namespace Phue\Core\Console;

use ReflectionMethod;

/**
 * Abstract base command with convention-based sub-command routing.
 *
 * Extend this class to get automatic sub-command routing based on public method names.
 * Convention: public methods (except getName, getDescription) become sub-commands.
 *
 * Usage:
 * - `phue hello:world` -> calls `world()` method
 * - `phue hello:test` -> calls `test()` method
 * - `phue hello` -> shows help (default behavior)
 *
 * @package Phue\Core\Console
 */
abstract class BaseCommand implements CommandInterface
{
    /**
     * The group this command belongs to.
     *
     * Commands in the same group are listed together in the help output.
     */
    protected string $group = 'General';

    /**
     * Gets the command group.
     *
     * @return string
     */
    public function getGroup(): string
    {
        return $this->group;
    }

    /**
     * Executes the command with automatic sub-command routing.
     *
     * @param array<int, string> $args Command line arguments.
     * @param resource           $outputStream Standard output stream.
     * @param resource           $errorStream  Standard error stream.
     *
     * @return int Exit status code (0 for success, non-zero for error).
     */
    public function execute(array $args = [], $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        // If no sub-command provided, show help
        if (empty($args)) {
            return $this->showHelp($outputStream);
        }

        $subCommand = array_shift($args);
        $method = $subCommand;

        if (!method_exists($this, $method)) {
            fwrite($errorStream, sprintf("\033[31mError: Sub-command \"%s\" not found.\033[0m\n\n", $subCommand));
            $this->showHelp($errorStream);
            return self::FAILURE;
        }

        // Check if method is a valid sub-command (public, not constructor, not base interface methods)
        $methodObj = new ReflectionMethod($this, $method);
        if (!$methodObj->isPublic()) {
            fwrite($errorStream, sprintf("\033[31mError: Sub-command \"%s\" is not accessible.\033[0m\n\n", $subCommand));
            $this->showHelp($errorStream);
            return self::FAILURE;
        }

        // Skip base class methods
        $declaringClass = $methodObj->getDeclaringClass()->getName();
        if ($declaringClass === self::class) {
            fwrite($errorStream, sprintf("\033[31mError: Sub-command \"%s\" is not a valid sub-command.\033[0m\n\n", $subCommand));
            $this->showHelp($errorStream);
            return self::FAILURE;
        }

        try {
            return $this->$method($args, $outputStream, $errorStream);
        } catch (\Throwable $e) {
            fwrite($errorStream, sprintf("\033[31mCommand failed: %s\033[0m\n", $e->getMessage()));
            return self::FAILURE;
        }
    }

    /**
     * Shows help for this command, listing available sub-commands.
     */
    protected function showHelp($outputStream): int
    {
        $subCommands = $this->getSubCommands();

        fwrite($outputStream, sprintf(
            "\033[33mUsage:\033[0m\n  ./phue %s:<sub-command> [arguments]\n\n",
            $this->getName()
        ));

        if (empty($subCommands)) {
            fwrite($outputStream, "No sub-commands available.\n\n");
            return self::SUCCESS;
        }

        fwrite($outputStream, "\033[33mAvailable sub-commands:\033[0m\n");
        foreach ($subCommands as $name => $description) {
            fwrite($outputStream, sprintf("  \033[32m%-20s\033[0m %s\n", $name, $description));
        }
        fwrite($outputStream, "\n");

        return self::SUCCESS;
    }

    /**
     * Returns available sub-commands with their descriptions.
     *
     * Override to provide custom descriptions, or use reflection (default).
     *
     * @return array<string, string> Sub-command name => description
     */
    public function getSubCommands(): array
    {
        $methods = get_class_methods($this);
        $subCommands = [];

        foreach ($methods as $method) {
            // Skip base class methods and interface methods
            if (in_array($method, ['getName', 'getDescription', 'execute', 'showHelp', 'getSubCommands'], true)) {
                continue;
            }

            // Only public methods from derived class
            $methodObj = new ReflectionMethod($this, $method);
            if (!$methodObj->isPublic() || $methodObj->getDeclaringClass()->getName() === self::class) {
                continue;
            }

            // Try to get description from docblock
            $description = $this->getMethodDescription($method);
            $subCommands[$method] = $description ?: 'No description';
        }

        return $subCommands;
    }

    /**
     * Extracts description from method docblock @description tag.
     */
    private function getMethodDescription(string $method): ?string
    {
        $methodObj = new ReflectionMethod($this, $method);
        $docComment = $methodObj->getDocComment();

        if ($docComment === false) {
            return null;
        }

        if (preg_match('/@description\s+(.+)/', $docComment, $matches)) {
            return trim($matches[1]);
        }

        // Try first sentence of summary
        if (preg_match('/^\s*\*\s*(.+?)(?:\n\s*\*|\n\s*\*\/)/s', $docComment, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}