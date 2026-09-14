<?php

namespace Phue\Core\Console;

/**
 * Interface that all CLI commands must implement.
 *
 * @package Phue\Core\Console
 */
interface CommandInterface
{

    /*
     * SUCCESS status codes.
     */
    public const SUCCESS = 0;

    /**
     * FAILURE status codes.
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
     * Executes the command with the provided arguments.
     *
     * @param array<int, string> $args Command line arguments (excluding script and command name).
     * @return int Exit status code (0 for success, non-zero for error).
     */
    public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int;
}