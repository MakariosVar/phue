<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job that throws with the reason it was given, for the dead-letter and command paths.
 *
 * Named apart from FailingJob on purpose: that one gives up deliberately, and the difference
 * between deciding to fail and failing by accident is the thing the dead-letter store is for.
 */
final class RuntimeFailingJob implements JobInterface
{
    public function __construct(
        public readonly string $reason = 'it failed'
    ) {
    }

    public function handle(JobContext $context): void
    {
        throw new \RuntimeException($this->reason);
    }
}
