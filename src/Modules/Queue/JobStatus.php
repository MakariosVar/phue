<?php

declare(strict_types=1);

namespace Phue\Modules\Queue;

/**
 * Lifecycle state of a stored job.
 *
 * Deliberately three states and not four. "Failed" is not one of them because a job that has
 * exhausted its retries has left the live table entirely — it is now a record in the dead-letter
 * store, which has a different shape (it keeps the exception) and a different retention policy.
 * Folding it in here as a fourth status would make every backend invent a place to put the
 * exception, and the in-memory drivers would end up holding state the database drivers cannot.
 *
 * @package Phue\Modules\Queue
 */
enum JobStatus: string
{
    /** Stored and waiting to be claimed. */
    case Pending = 'pending';

    /** Claimed by a worker, held until its lease runs out. */
    case Reserved = 'reserved';

    /** Finished successfully and no longer claimable. */
    case Completed = 'completed';
}