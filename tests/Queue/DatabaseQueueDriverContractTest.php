<?php

declare(strict_types=1);

namespace Phue\Tests\Queue;

use Phue\Core\Database\Connection;
use Phue\Modules\Queue\Driver\DatabaseQueueDriver;
use Phue\Modules\Queue\QueueConfig;
use Phue\Modules\Queue\QueueDriverInterface;
use Phue\Modules\Queue\Schema\QueueSchema;
use Phue\Tests\Support\SqliteFactory;

/**
 * The shared driver contract, run against a real SQL table.
 *
 * This is the test that matters most in the whole queue suite. Every other test says the database
 * driver behaves the way its author intended; this one says it behaves the way the interface
 * promises, by running the same assertions that already pass for the in-memory store. When the
 * contract is wrong, this is where it shows up — before a second worker in production does.
 *
 * @package Phue\Tests\Queue
 */
final class DatabaseQueueDriverContractTest extends QueueDriverContract
{
    protected function driver(): QueueDriverInterface
    {
        $connection = SqliteFactory::memory();
        $config = QueueConfig::fromArray(['driver' => 'database']);

        (new QueueSchema($connection, $config->table))->install();

        return new DatabaseQueueDriver($connection, $config);
    }

    /**
     * SQLite in memory is scoped to the connection that opened it, so each test needs its own.
     * Reusing one would leak reservations between tests and turn an ownership assertion into a
     * test of the previous test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertNotNull($this->driver, 'the driver under test must be built');
    }

    /**
     * A separate connection for the assertions that need one, since the driver's is private.
     */
    protected function connection(): Connection
    {
        return SqliteFactory::memory();
    }
}