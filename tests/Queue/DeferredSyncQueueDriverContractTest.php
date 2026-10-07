<?php

declare(strict_types=1);

namespace Phue\Tests\Queue;

use Phue\Modules\Queue\QueueDriverInterface;

/**
 * The same contract, run against a second implementation written independently.
 *
 * If these pass, the interface is implementable and the contract is not a description of one
 * particular store. If a future driver fails here, the interface is what changed, not the store.
 *
 * @package Phue\Tests\Queue
 */
final class DeferredSyncQueueDriverContractTest extends QueueDriverContract
{
    protected function driver(): QueueDriverInterface
    {
        return new DeferredSyncQueueDriver();
    }
}
