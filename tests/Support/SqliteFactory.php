<?php

namespace Phue\Tests\Support;

use Phue\Core\Database\Connection;

/**
 * Shared in-memory SQLite harness.
 *
 * Tier-1 unit tests of a module MUST only depend on this Support layer
 * plus their own module — never on another module.
 */
final class SqliteFactory
{
    public static function memory(): Connection
    {
        return new Connection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }
}