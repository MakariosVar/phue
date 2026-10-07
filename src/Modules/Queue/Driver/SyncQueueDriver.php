<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Driver;

use Phue\Modules\Queue\ExecutionModel;
use Phue\Modules\Queue\JobEnvelope;
use Phue\Modules\Queue\JobStatus;
use Phue\Modules\Queue\QueueDriverInterface;

/**
 * Runs jobs the moment they are pushed, and stores them only long enough to prove it worked.
 *
 * This is the default driver, which is a deliberate choice about where a mistake surfaces. A
 * queue that quietly does nothing is the worst default available: the job appears to succeed, the
 * feature appears to work in development, and the difference only shows up in production where
 * nobody is running a worker. Running the job inline means the developer sees the failure, the
 * stack trace and the fix while they are still looking at the code that caused it. Real
 * asynchrony is one config key away.
 *
 * Two things this driver is not, because both are easy to assume and expensive to discover:
 *
 *  - it is not durable. The store is an array in this process and dies with it. There is no
 *    resume after a restart, because there is nothing on disk to resume from.
 *  - it is not a stand-in for the database driver in tests. It implements the same interface and
 *    both drivers run the same contract test suite, but a memory store cannot show you what a
 *    compare-and-set does under concurrency. Tests about job safety still need a real backend.
 *
 * @package Phue\Modules\Queue\Driver
 */
final class SyncQueueDriver implements QueueDriverInterface
{
    /**
     * Upper bound on retained envelopes.
     *
     * A long-running process pushing without draining would otherwise grow this array until the
     * memory limit killed it, taking the job that was being pushed with it. Eviction is counted
     * and reported through evictedCount(), so a lost job is visible rather than quiet.
     */
    public const DEFAULT_MAX_ENTRIES = 256;

    /** @var array<string, JobEnvelope> Stored envelopes, keyed by id, in insertion order. */
    private array $entries = [];

    /** @var list<array{at: int, error: string, jobClass: string, queue: string}> */
    private array $deadLetters = [];

    /** Monotonic, so a job's id says roughly when it was queued. */
    private int $nextId = 1;

    /** Running count of evictions, reported so a lost job is visible. */
    private int $evicted = 0;

    /**
     * @param int $maxEntries How many envelopes to retain before evicting. At least one.
     *
     * @throws \InvalidArgumentException When $maxEntries is below one.
     */
    public function __construct(
        private readonly int $maxEntries = self::DEFAULT_MAX_ENTRIES
    ) {
        if ($this->maxEntries < 1) {
            throw new \InvalidArgumentException(
                'The sync queue driver needs room for at least one entry.'
            );
        }
    }

    /**
     * The name this driver is configured under.
     */
    public function name(): string
    {
        return 'sync';
    }

    /**
     * Inline: pushing a job runs it before push() returns.
     */
    public function executionModel(): ExecutionModel
    {
        return ExecutionModel::Inline;
    }

    /**
     * False. Nothing is ever waiting, because jobs do not wait.
     */
    public function supportsBlocking(): bool
    {
        return false;
    }

    /**
     * False. A job delayed by five minutes cannot be held anywhere that survives the request that
     * queued it, so the manager refuses a delayed push rather than running the job immediately.
     */
    public function supportsDelayed(): bool
    {
        return false;
    }

    /**
     * Store a job and assign it an id.
     *
     * @param JobEnvelope $envelope The job, with no id and no reservation yet.
     *
     * @return string The assigned id.
     */
    public function push(JobEnvelope $envelope): string
    {
        $id = (string) $this->nextId++;

        $this->entries[$id] = new JobEnvelope(
            id: $id,
            queue: $envelope->queue,
            jobClass: $envelope->jobClass,
            payload: $envelope->payload,
            status: $envelope->status,
            attempts: $envelope->attempts,
            availableAt: $envelope->availableAt,
            createdAt: $envelope->createdAt,
        );

        $this->makeRoom();

        return $id;
    }

    /**
     * Claim one job, or null if there is nothing to claim.
     *
     * @param string|null $queue        One queue, or null for any.
     * @param string      $workerId     Who is claiming.
     * @param int         $leaseSeconds How long the claim is good for.
     * @param int|null    $now          Current time, injectable so tests need not sleep.
     *
     * @return JobEnvelope|null Reserved for $workerId.
     */
    public function reserve(?string $queue, string $workerId, int $leaseSeconds, ?int $now = null): ?JobEnvelope
    {
        $now ??= time();

        foreach ($this->claimable($queue, $now) as $id) {
            $claimed = $this->entries[$id]->reserved($workerId, $now, $now + $leaseSeconds);
            $this->entries[$id] = $claimed;

            return $claimed;
        }

        return null;
    }

    /**
     * Ids of the jobs that may be claimed now, soonest due first.
     *
     * Two things are being handled here that are easy to leave out and expensive to discover:
     *
     *  - A job whose lease has run out is claimable again. Nothing observes a worker dying, so
     *    an expired lease is the only signal that the job is unattended. Treating a reserved job
     *    as untouchable strands it forever.
     *  - Ordering is by when a job became due, not by when it was pushed. A job queued for five
     *    minutes' time must not sit behind one queued a second ago, and insertion order cannot
     *    express that.
     *
     * @return list<string>
     */
    private function claimable(?string $queue, int $now): array
    {
        $candidates = [];

        foreach ($this->entries as $id => $entry) {
            if ($queue !== null && $entry->queue !== $queue) {
                continue;
            }

            if ($entry->status === JobStatus::Pending) {
                if ($entry->availableAt <= $now) {
                    $candidates[$id] = [$entry->availableAt, (int) $id];
                }

                continue;
            }

            if ($entry->status === JobStatus::Reserved && $entry->leaseExpired($now)) {
                $candidates[$id] = [$entry->availableAt, (int) $id];
            }
        }

        asort($candidates);

        return array_map(strval(...), array_keys($candidates));
    }

    /**
     * Mark a job done, if this worker still owns it.
     *
     * @param JobEnvelope $envelope The reserved job.
     * @param string      $workerId Who claims ownership.
     *
     * @return bool False if the job is gone or belongs to someone else.
     */
    public function complete(JobEnvelope $envelope, string $workerId): bool
    {
        if (!$this->isOwnedBy($envelope, $workerId)) {
            return false;
        }

        $this->entries[$envelope->id] = $envelope->completedAt(time());

        return true;
    }

    /**
     * Put a reserved job back on the pending list, invisible until the delay is up.
     *
     * The only finish method that takes a clock. Here the current time decides when the job
     * becomes claimable again, which is behaviour; in complete() and fail() it only stamps a
     * record, which is metadata.
     *
     * @param JobEnvelope $envelope    The reserved job.
     * @param string      $workerId    Who claims ownership.
     * @param int         $delaySeconds Wait before it can be claimed again.
     * @param int|null    $now         Current time, injectable so tests need not sleep.
     *
     * @return bool False if the job is gone or belongs to someone else.
     */
    public function release(JobEnvelope $envelope, string $workerId, int $delaySeconds, ?int $now = null): bool
    {
        if (!$this->isOwnedBy($envelope, $workerId)) {
            return false;
        }

        $now ??= time();
        $this->entries[$envelope->id] = $envelope->pendingFrom($now, $now + max(0, $delaySeconds));

        return true;
    }

    /**
     * Drop a job and record why.
     *
     * @param JobEnvelope  $envelope The reserved job.
     * @param string       $workerId Who claims ownership.
     * @param \Throwable   $error    What went wrong.
     * @param bool         $willRetry Whether the manager expected another attempt. Recorded for
     *                                diagnostics; a driver that disagreed with the decision has
     *                                already lost it by the time it is called.
     *
     * @return bool False if the job is gone or belongs to someone else.
     */
    public function fail(JobEnvelope $envelope, string $workerId, \Throwable $error, bool $willRetry): bool
    {
        if (!$this->isOwnedBy($envelope, $workerId)) {
            return false;
        }

        unset($this->entries[$envelope->id]);
        $this->deadLetters[] = [
            'at' => time(),
            'error' => $error->getMessage(),
            'jobClass' => $envelope->jobClass,
            'queue' => $envelope->queue,
        ];

        return true;
    }

    /**
     * Counts per status.
     *
     * @param string|null $queue One queue, or null for everything.
     *
     * @return array{pending: int, reserved: int, completed: int, failed: int, total: int}
     */
    public function stats(?string $queue = null): array
    {
        $counts = ['pending' => 0, 'reserved' => 0, 'completed' => 0];

        foreach ($this->entries as $entry) {
            if ($queue !== null && $entry->queue !== $queue) {
                continue;
            }

            $counts[$entry->status->value]++;
        }

        $failed = 0;

        foreach ($this->deadLetters as $dead) {
            if ($queue === null || $dead['queue'] === $queue) {
                $failed++;
            }
        }

        return $counts + [
            'failed' => $failed,
            'total' => array_sum($counts) + $failed,
        ];
    }

    /**
     * Forget completed jobs older than the given age.
     *
     * @param int $completedOlderThanSeconds Age at which a completed job may be discarded.
     *
     * @return int How many were removed.
     */
    public function prune(int $completedOlderThanSeconds): int
    {
        $cutoff = time() - max(0, $completedOlderThanSeconds);
        $removed = 0;

        foreach ($this->entries as $id => $entry) {
            if ($entry->status === JobStatus::Completed && ($entry->completedAt ?? 0) <= $cutoff) {
                unset($this->entries[$id]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * What this driver keeps that the others do not: the dead letters, for assertions.
     *
     * Named so that no caller can reach for it by accident. A test that uses it is a test that
     * only runs against the default driver, which is exactly the kind of test that stops
     * catching anything once the driver changes.
     *
     * @return list<array{at: int, error: string, jobClass: string, queue: string}>
     */
    public function deadLetters(): array
    {
        return $this->deadLetters;
    }

    /**
     * How many envelopes the store dropped because it was full.
     */
    public function evictedCount(): int
    {
        return $this->evicted;
    }

    /**
     * The compare-and-set, expressed for an array store.
     *
     * A worker whose lease expired mid-job still holds the envelope it was given, and calling
     * complete() on it must not succeed: another worker may own that job now, and acknowledging
     * it would mark work as done that was never done. Comparing reservedBy is the same guard the
     * SQL driver expresses as a WHERE clause on the owning worker id.
     */
    private function isOwnedBy(JobEnvelope $envelope, string $workerId): bool
    {
        $stored = $this->entries[$envelope->id] ?? null;

        return $stored !== null
            && $stored->status === JobStatus::Reserved
            && $stored->reservedBy === $workerId;
    }

    /**
     * Keep the store within its bound, dropping completed entries first.
     */
    private function makeRoom(): void
    {
        while (count($this->entries) >= $this->maxEntries) {
            $this->evictOne();
            $this->evicted++;
        }
    }

    /**
     * Completed entries go first: nothing is waiting on them, and losing one costs nothing but a
     * stats number. Only when every entry is still live does one get dropped, and that is
     * counted through evictedCount() rather than passing unnoticed.
     */
    private function evictOne(): void
    {
        foreach ($this->entries as $id => $entry) {
            if ($entry->status === JobStatus::Completed) {
                unset($this->entries[$id]);

                return;
            }
        }

        $id = array_key_first($this->entries);

        if ($id !== null) {
            unset($this->entries[$id]);
        }
    }
}