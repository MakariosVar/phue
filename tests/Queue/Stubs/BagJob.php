<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job that reads and writes the attempt bag.
 */
final class BagJob implements JobInterface
{
    /** @var array<string, mixed> */
    public static array $bag = [];

    public function handle(JobContext $context): void
    {
        $previous = (int) $context->get('seen', 0);

        if ($previous === 0) {
            self::$bag = [];
        }

        $context->put('seen', $previous + 1);
        self::$bag = $context->all();
    }
}
