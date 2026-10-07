<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

use Phue\Core\Container;
use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;

/**
 * A job that uses a request-scoped service and leaves state on it.
 *
 * This is the property the worker exists for. A worker runs many jobs in one process, and
 * Application::handle() — which takes a fresh scope and flushes the last one — is never entered
 * from a console. So without the worker doing it itself, the second job finds the first job's
 * scoped instance still in place, with no error anywhere: the state simply arrives already used.
 */
final class ScopeLeakingJob implements JobInterface
{
    /** @var list<int> The counter value each run found. */
    public static array $counts = [];

    public function __construct(
        public readonly bool $alsoThrows = false
    ) {
    }

    public function handle(JobContext $context): void
    {
        $container = Container::getInstance();

        if ($container === null || !$container->bound(ScopedCounter::class)) {
            return;
        }

        $counter = $container->get(ScopedCounter::class);

        if (!$counter instanceof ScopedCounter) {
            return;
        }

        self::$counts[] = $counter->count;
        $counter->count++;

        if ($this->alsoThrows) {
            throw new \RuntimeException('after touching it, so the scope still has to be cleaned up');
        }
    }
}
