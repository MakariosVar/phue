<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job holding a live object, which is the mistake the codec exists to catch.
 */
final class ObjectArgumentJob implements JobInterface
{
    public function __construct(
        public readonly EntityStub $user
    ) {
    }

    public function handle(JobContext $context): void
    {
    }
}
