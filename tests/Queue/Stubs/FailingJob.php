<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job that gives up on itself, without throwing.
 */
final class FailingJob implements JobInterface
{
    public function handle(JobContext $context): void
    {
        $context->fail(new \DomainException('the input was never going to be valid'));
    }
}
