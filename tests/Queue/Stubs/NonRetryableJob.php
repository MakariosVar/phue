<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\Exception\NonRetryableException;
use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job whose failure cannot be fixed by trying again.
 */
final class NonRetryableJob implements JobInterface
{
    public function handle(JobContext $context): void
    {
        throw new NonRetryableException('this will never succeed');
    }
}
