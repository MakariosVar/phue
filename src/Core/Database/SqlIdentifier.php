<?php

declare(strict_types=1);

namespace Phue\Core\Database;

use InvalidArgumentException;

final class SqlIdentifier
{
    /**
     * Validates SQL identifiers (table names, column names) to prevent injection.
     */
    public static function validate(string $name): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Invalid database identifier name: '{$name}'");
        }

        return $name;
    }

    /**
     * Quotes an identifier based on the driver/dialect name.
     */
    public static function quote(string $name, string $driverName): string
    {
        self::validate($name);

        return match (strtolower($driverName)) {
            'pgsql', 'postgres', 'postgresql' => '"' . str_replace('"', '""', $name) . '"',
            default => '`' . str_replace('`', '``', $name) . '`', // MySQL & SQLite
        };
    }
}