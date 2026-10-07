<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job that does nothing and succeeds, for filling a queue in a test.
 */
final class CountingJob implements JobInterface
{
    public static int $runs = 0;

    public function handle(JobContext $context): void
    {
        self::$runs++;
    }
}
