<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job with no state at all, so its payload is legitimately empty.
 */
final class NoConstructorJob implements JobInterface
{
    public function handle(JobContext $context): void
    {
    }
}
