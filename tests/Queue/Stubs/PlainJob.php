<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * The smallest useful job: a constructor and a handle().
 *
 * If this cannot be queued without writing anything else, the contract is wrong.
 *
 * The record of what ran is static, because the object that runs is a replica built from the
 * payload. Recording on the instance would test nothing but the identity of the copy.
 */
final class PlainJob implements JobInterface
{
    /** @var list<string> What handle() was called with, across every instance. */
    public static array $ran = [];

    /** Counts the runs of this particular instance, so a replica can be told from the original. */
    public int $handledOn = 0;

    public function __construct(
        public readonly string $subject = 'default',
        public readonly int $count = 0,
    ) {
    }

    public function handle(JobContext $context): void
    {
        $this->handledOn++;
        self::$ran[] = $this->subject . ':' . $this->count . '@' . $context->attempts;
    }
}
