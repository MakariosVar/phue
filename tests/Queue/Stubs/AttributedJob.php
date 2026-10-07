<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\Attribute\Job;
use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job that overrides the queue defaults for itself.
 */
#[Job(queue: 'emails', tries: 1, timeout: 5, backoff: 0)]
final class AttributedJob implements JobInterface
{
    public function handle(JobContext $context): void
    {
    }
}
