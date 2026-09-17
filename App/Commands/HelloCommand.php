<?php

declare(strict_types=1);

namespace App\Commands;

use Phue\Core\Console\BaseCommand;

/**
 * HelloCommand - Example command with sub-commands.
 *
 * Usage:
 *   phue hello:world
 *   phue hello:test
 *   phue hello         # shows help
 */
final class HelloCommand extends BaseCommand
{
    /**
     * Retrieves command identifier name.
     */
    public function getName(): string
    {
        return 'hello';
    }

    /**
     * Retrieves command description.
     */
    public function getDescription(): string
    {
        return 'The main Hello-er command for CLI';
    }

    /**
     * Executes the 'world' sub-command.
     *
     * @description Prints "Hello World!"
     */
    public function world(array $args, $out, $err): int
    {
        echo "Hello World!\n";
        return self::SUCCESS;
    }

    /**
     * Executes the 'test' sub-command.
     *
     * @description Prints "Hello Test!"
     */
    public function test(array $args, $out, $err): int
    {
        echo "Hello Test!\n";
        return self::SUCCESS;
    }
}