<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job that succeeds and says which one it was.
 */
final class RecordingJob implements JobInterface
{
    public function __construct(
        public readonly int $ordinal = 0
    ) {
    }

    public function handle(JobContext $context): void
    {
    }
}
