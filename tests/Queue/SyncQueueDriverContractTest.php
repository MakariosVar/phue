<?php

declare(strict_types=1);

namespace Phue\Tests\Queue;

use Phue\Modules\Queue\Driver\SyncQueueDriver;
use Phue\Modules\Queue\QueueDriverInterface;

/**
 * The shared driver contract, run against the default in-memory store.
 *
 * @package Phue\Tests\Queue
 */
final class SyncQueueDriverContractTest extends QueueDriverContract
{
    protected function driver(): QueueDriverInterface
    {
        return new SyncQueueDriver();
    }
}
