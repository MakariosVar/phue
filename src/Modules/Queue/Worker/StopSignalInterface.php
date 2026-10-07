<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Worker;

/**
 * Whether something has asked the worker to stop.
 *
 * An interface for one boolean, because the alternative is untestable. The real answer comes from
 * signal handlers, and the ext-pcntl that provides them is not installed everywhere — it is not in
 * this project's own development image — so a worker that read signals directly could only ever be
 * tested in the one environment that has them, which is exactly the environment where the graceful
 * path is least likely to be broken.
 *
 * A worker asked to stop finishes the job in hand and then stops. Stopping mid-job would leave the
 * job to time out on its lease and be run again by somebody else, which is a worse outcome than
 * taking one extra second.
 *
 * @package Phue\Modules\Queue\Worker
 */
interface StopSignalInterface
{
    /**
     * Whether a stop has been requested and not yet acted on.
     */
    public function stopRequested(): bool;
}