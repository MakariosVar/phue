<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job that puts itself back on the queue instead of completing.
 */
final class ReleasingJob implements JobInterface
{
    public function __construct(
        public readonly int $delay = 30
    ) {
    }

    public function handle(JobContext $context): void
    {
        $context->release($this->delay);
    }
}
