<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job that records the attempt number it was handed.
 */
final class AttemptRecordingJob implements JobInterface
{
    /** @var list<int> */
    public static array $seen = [];

    public function handle(JobContext $context): void
    {
        self::$seen[] = $context->attempts;
    }
}
