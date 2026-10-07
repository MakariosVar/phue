<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\Attribute\Job;
use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job asking for things that cannot be true. It has to be possible to write this and still
 * boot, because the job is not under the application's control when the attribute is read.
 */
#[Job(queue: 'not a legal name', tries: 0, timeout: 0)]
final class BadAttributeJob implements JobInterface
{
    public function handle(JobContext $context): void
    {
    }
}
