<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job that counts how many times it ran, across every instance.
 *
 * Static because the job that runs is a replica built from the payload, so an instance counter
 * would report nothing.
 */
final class AttemptCountingJob implements JobInterface
{
    public static int $runs = 0;

    public function __construct(
        public readonly string $label = 'x'
    ) {
    }

    public function handle(JobContext $context): void
    {
        self::$runs++;
    }
}
