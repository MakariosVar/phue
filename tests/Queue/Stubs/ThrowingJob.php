<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job that always throws the same failure.
 *
 * The promoted property is public because that is what makes it a payload; a private one would be
 * refused by the codec, which is the whole reason the codec exists.
 */
final class ThrowingJob implements JobInterface
{
    /** @var list<int> Attempt numbers this job was run on, across instances. */
    public static array $attempts = [];

    public function __construct(
        public readonly string $message = 'nope'
    ) {
    }

    public function handle(JobContext $context): void
    {
        self::$attempts[] = $context->attempts;

        throw new \RuntimeException($this->message);
    }
}
