<?php

declare(strict_types=1);

namespace Phue\Modules\Queue;

/**
 * The storage contract of the queue.
 *
 * A driver stores envelopes and nothing else. It never instantiates a job, never decides whether
 * something should be retried, and never loops — those belong to the manager and the worker, and
 * a driver that also did them would have to be rewritten to get either one right.
 *
 * Every method that finishes a job returns a bool rather than void, and that bool means "this
 * driver actually made the transition". It is not a formality: a worker whose lease expired
 * mid-job will call complete() for a job that another worker has already taken, and the write
 * has to fail rather than quietly succeed against a row it no longer owns. See QueueDriverInterface
 * on how that is enforced.
 *
 * @package Phue\Modules\Queue
 */
interface QueueDriverInterface
{
    /**
     * Name as configured, for diagnostics.
     */
    public function name(): string;

    /**
     * Whether pushing a job also runs it.
     *
     * Inline drivers have nothing to reserve. Deferred drivers return from push() and leave the
     * work for a worker.
     */
    public function executionModel(): ExecutionModel;

    /**
     * Whether reserve() can wait for work instead of returning null when there is none.
     *
     * A driver that answers true owns its own blocking strategy; the worker then calls it once
     * and is woken when a job arrives. A driver that answers false is polled by the worker,
     * which sleeps between empty reserves. The default is false because the polling loop is the
     * one that works everywhere.
     */
    public function supportsBlocking(): bool;

    /**
     * Whether this driver can make a job invisible until a future time.
     *
     * A driver that answers false is telling the manager that a delayed push cannot be honoured,
     * and the manager refuses the push rather than running the job immediately — a job that was
     * asked to wait five minutes and did not is worse than one that was never accepted.
     */
    public function supportsDelayed(): bool;

    /**
     * Store a job and return its id.
     *
     * The envelope arrives with an empty id and no reservation; the driver assigns one.
     *
     * @throws Exception\SchemaException When the store a driver needs is not there.
     */
    public function push(JobEnvelope $envelope): string;

    /**
     * Claim one job, or null if there is nothing to claim.
     *
     * @param string|null $queue         One queue, or null for any queue this driver serves.
     * @param string      $workerId      Who is claiming. The driver records this as the owner,
     *                                   because it is the one that has to enforce ownership on
     *                                   complete(), release() and fail().
     * @param int         $leaseSeconds  How long the claim is good for.
     * @param int|null    $now           Current time, injectable so tests need not sleep.
     *
     * Implementations are expected to make this a single atomic step. Two workers asking at the
     * same moment must not come away with the same job, and the way to achieve that differs per
     * backend (a compare-and-set update, a blocking pop that moves the job, a stream read with a
     * consumer group). A driver that reads a list and deletes afterwards cannot satisfy this.
     *
     * @return JobEnvelope|null Reserved for $workerId, with attempts already incremented.
     */
    public function reserve(?string $queue, string $workerId, int $leaseSeconds, ?int $now = null): ?JobEnvelope;

    /**
     * Mark a job done. Returns false if this worker no longer owns it.
     */
    public function complete(JobEnvelope $envelope, string $workerId): bool;

    /**
     * Put a reserved job back on the pending list, invisible for $delaySeconds.
     *
     * @param int|null $now Current time, injectable so tests need not sleep. The clock matters
     *                      here in a way it does not for complete() or fail(), because it decides
     *                      when the job becomes claimable again rather than just stamping a row.
     *
     * @return bool False if this worker no longer owns the job.
     */
    public function release(JobEnvelope $envelope, string $workerId, int $delaySeconds, ?int $now = null): bool;

    /**
     * Give up on a job: remove it and record why, with $willRetry saying whether the retry policy
     * expected another attempt.
     *
     * $willRetry is informational for the dead-letter record, not a veto. The manager has
     * already decided; a driver that disagrees with the decision loses the argument.
     *
     * Returns false if this worker no longer owns the job.
     */
    public function fail(JobEnvelope $envelope, string $workerId, \Throwable $error, bool $willRetry): bool;

    /**
     * Counts per status, optionally for one queue only.
     *
     * @return array{pending: int, reserved: int, completed: int, failed: int, total: int}
     */
    public function stats(?string $queue = null): array;

    /**
     * Forget completed jobs older than the given age. Returns how many were removed.
     */
    public function prune(int $completedOlderThanSeconds): int;
}