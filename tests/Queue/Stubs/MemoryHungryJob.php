<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job that keeps a little more memory than it should, every time it runs.
 *
 * Stands in for the ordinary cause: a payload it never frees, an image library that does not
 * release, a cache nobody trims. One job leaking a little is not a problem; a worker running for
 * hours is.
 *
 * Sized against the headroom in the test rather than to be dramatic. A job that allocates more than
 * the whole remaining limit is killed by the runtime before any worker could notice, and no ceiling
 * in a loop can prevent that — which is exactly why the ceiling exists between jobs rather than
 * inside them.
 */
final class MemoryHungryJob implements JobInterface
{
    /** @var list<string> Kept on purpose, so the memory stays used. */
    private static array $held = [];

    public function __construct(
        public readonly int $kilobytes = 512
    ) {
    }

    public function handle(JobContext $context): void
    {
        self::$held[] = str_repeat('x', $this->kilobytes * 1024);
    }
}
