<?php

declare(strict_types=1);

namespace Phue\Modules\Queue;

/**
 * Whether pushing a job also runs it.
 *
 * This is asked as a question of the driver rather than decided by comparing its name against
 * the config, because name-based dispatch is the thing that makes a driver unreplaceable. A
 * third-party driver that wants inline execution should not have to be called "sync".
 *
 * @package Phue\Modules\Queue
 */
enum ExecutionModel
{
    /** push() returns once the job has run. Nothing is ever waiting to be picked up later. */
    case Inline;

    /** push() stores the job and returns. Something else has to reserve it and run it. */
    case Deferred;
}