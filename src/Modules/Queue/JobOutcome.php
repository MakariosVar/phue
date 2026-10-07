<?php

declare(strict_types=1);

namespace Phue\Modules\Queue;

/**
 * How one attempt ended.
 *
 * The manager knows this and the worker needs it, so it is returned rather than measured. Counting
 * successes by comparing stats() before and after each job would put two extra queries on every
 * single job — on the one code path where an extra query is most expensive and least noticed.
 *
 * @package Phue\Modules\Queue
 */
enum JobOutcome
{
    /** The job ran to the end and was acknowledged. */
    case Completed;

    /** The job failed and was put back for another attempt. */
    case Retried;

    /** The job failed and was moved to the dead-letter store. */
    case Failed;

    /**
     * Whether this outcome is the end of the job.
     *
     * A retry is not a success and not a failure; it is a job that will be back, and a worker
     * reporting "3 failed" for a queue where every job is on its first attempt is reporting noise.
     */
    public function isFinal(): bool
    {
        return $this !== self::Retried;
    }
}
