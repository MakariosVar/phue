<?php

declare(strict_types=1);

namespace Phue\Core\Console;

/**
 * Marker interface for convention-based commands with auto sub-command routing.
 *
 * Implement this interface (or extend {@see BaseCommand}) to get automatic
 * sub-command routing based on public method names.
 *
 * Convention:
 * - Public methods (except getName, getDescription) become sub-commands
 * - Command invoked as `command:sub` routes to method `sub()`
 * - Framework provides default `execute()` that routes to sub-method
 *
 * @package Phue\Core\Console
 */
interface CommandInterface extends BaseCommandInterface
{
    // No additional methods required
    // Convention: all public methods (except getName, getDescription) = sub-commands
}