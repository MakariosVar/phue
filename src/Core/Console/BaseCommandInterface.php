<?php

declare(strict_types=1);

namespace Phue\Core\Console;

/**
 * Base interface for all CLI commands.
 *
 * Implement this directly for full control over command execution,
 * or extend {@see BaseCommand} for convention-based sub-command routing.
 *
 * @package Phue\Core\Console
 */
interface BaseCommandInterface
{
    /**
     * SUCCESS status code.
     */
    public const SUCCESS = 0;

    /**
     * FAILURE status code.
     */
    public const FAILURE = 1;

    /**
     * Gets the unique command name (e.g., 'serve', 'make:controller').
     */
    public function getName(): string;

    /**
     * Gets a short description of the command for listings.
     */
    public function getDescription(): string;

    /**
     * Gets the command group form help listing (e.g. 'General', 'Build', 'Dev')
     * Command in the same group are listed together.
     */
    public function getGroup(): string;

    /**
     * Executes the command with the provided arguments.
     *
     * @param array<int, string> $args Command line arguments (excluding script and command name).
     * @return int Exit status code (0 for success, non-zero for error).
     */
    public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int;
}