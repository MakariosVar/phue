<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\Attribute\Job;
use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job that gets one attempt and insists on it.
 */
#[Job(tries: 1)]
final class StrictAttributeJob implements JobInterface
{
    public function handle(JobContext $context): void
    {
        throw new \RuntimeException('one attempt only');
    }
}
