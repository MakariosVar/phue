<?php

declare(strict_types=1);

namespace Phue\Tests\Queue\Stubs;

/**
 * A request-scoped service a job can leave state on.
 */
final class ScopedCounter
{
    public int $count = 0;
}
