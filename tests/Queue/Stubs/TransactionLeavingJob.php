<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Core\Container;
use Phue\Core\Database\Connection;
use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job that dies with a transaction still open.
 *
 * Which is what happens when a job throws and nobody rolls back. The worker has to, or the locks
 * and the row stay held until the connection times out — and on a busy table that is the whole
 * queue stalling behind one bad job.
 *
 * The connection is resolved from the container, because a job cannot carry one.
 */
final class TransactionLeavingJob implements JobInterface
{
    public function handle(JobContext $context): void
    {
        $connection = Container::getInstance()?->get(Connection::class);

        if (!$connection instanceof Connection) {
            throw new \RuntimeException('No connection in the container, so there is nothing to leave open.');
        }

        $connection->beginTransaction();

        throw new \RuntimeException('died mid-transaction');
    }
}
