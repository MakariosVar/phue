<?php

declare(strict_types=1);

namespace Phue\Modules\Queue;

/**
 * One stored job, as the drivers see it.
 *
 * Deliberately not the job object. A driver receives an already-encoded envelope and never
 * instantiates anything, which keeps it a data store: it can be unit tested without the job
 * class existing, cannot be tricked into running code by whatever is in the table, and has no
 * opinion about what a job is. The class name in here is a string that gets resolved once, by
 * the manager, against an allowlist.
 *
 * Immutable, because the transitions are the interesting part and a mutable envelope shared
 * between a worker and a driver is how a completed job gets re-completed.
 *
 * @package Phue\Modules\Queue
 */
final readonly class JobEnvelope
{
    /**
     * @param array<string, mixed> $payload Values for the job constructor, encoded or not
     *                                      depending on the driver.
     */
    public function __construct(
        public string $id,
        public string $queue,
        public string $jobClass,
        public array $payload,
        public JobStatus $status,
        public int $attempts,
        public int $availableAt,
        public ?int $reservedAt = null,
        public ?int $reservedUntil = null,
        public ?string $reservedBy = null,
        public ?int $completedAt = null,
        public ?int $createdAt = null,
    ) {
    }

    /**
     * A job as it is about to be stored: pending, due, with no id and no owner.
     *
     * @param string               $jobClass    Class that will rebuild this job.
     * @param array<string, mixed> $payload     Values for its constructor.
     * @param string               $queue       Queue to store it on.
     * @param int|null             $availableAt When it becomes claimable; now by default.
     * @param string|null          $id          Pre-assigned id, for drivers that have their own.
     */
    public static function forJob(
        string $jobClass,
        array $payload,
        string $queue,
        ?int $availableAt = null,
        ?int $id = null
    ): self {
        $now = time();

        return new self(
            id: $id ?? '',
            queue: $queue,
            jobClass: $jobClass,
            payload: $payload,
            status: JobStatus::Pending,
            attempts: 0,
            availableAt: $availableAt ?? $now,
            createdAt: $now,
        );
    }

    /**
     * As the worker sees it after a successful claim, with this attempt counted.
     *
     * Attempts are counted here rather than at push time, so the number means "how many times has
     * this actually been tried" and not "how long has it been waiting". A job that sat on the
     * queue for six hours and then failed has made one attempt, not one per hour it waited.
     */
    public function reserved(string $workerId, int $at, int $until): self
    {
        return new self(
            id: $this->id,
            queue: $this->queue,
            jobClass: $this->jobClass,
            payload: $this->payload,
            status: JobStatus::Reserved,
            attempts: $this->attempts + 1,
            availableAt: $this->availableAt,
            reservedAt: $at,
            reservedUntil: $until,
            reservedBy: $workerId,
            completedAt: null,
            createdAt: $this->createdAt,
        );
    }

    /**
     * Finished successfully: no longer claimable, and no longer owned by anyone.
     *
     * @param int $at When it completed.
     */
    public function completedAt(int $at): self
    {
        return new self(
            id: $this->id,
            queue: $this->queue,
            jobClass: $this->jobClass,
            payload: $this->payload,
            status: JobStatus::Completed,
            attempts: $this->attempts,
            availableAt: $this->availableAt,
            reservedAt: $this->reservedAt,
            reservedUntil: null,
            reservedBy: null,
            completedAt: $at,
            createdAt: $this->createdAt,
        );
    }

    /**
     * Back on the pending list, not visible until $at.
     *
     * Attempts is left where it is. It is the count of attempts made so far, and a release does
     * not make that untrue.
     */
    /**
     * Back on the pending list, invisible until $availableAt.
     *
     * @param int      $at          Now, for the record.
     * @param int|null $availableAt When it becomes claimable again; immediately by default.
     */
    public function pendingFrom(int $at, ?int $availableAt = null): self
    {
        return new self(
            id: $this->id,
            queue: $this->queue,
            jobClass: $this->jobClass,
            payload: $this->payload,
            status: JobStatus::Pending,
            attempts: $this->attempts,
            availableAt: $availableAt ?? $at,
            reservedAt: null,
            reservedUntil: null,
            reservedBy: null,
            completedAt: null,
            createdAt: $this->createdAt,
        );
    }

    /**
     * True once this job has been held past the end of its lease.
     *
     * How long a job may sit reserved is what stops a killed worker from stranding work: nothing
     * observes the death, the lease simply expires and the next worker to look finds it claimable
     * again. The cost is that a job can run twice, which is why delivery is at-least-once and why
     * handle() is expected to be idempotent.
     *
     * @see isFinalAttempt()
     */
    public function leaseExpired(int $now): bool
    {
        return $this->reservedUntil !== null && $this->reservedUntil <= $now;
    }

    /**
     * True when the next failure after this attempt ends the job.
     *
     * Attempts are counted at reserve time, not at push time, so a job that sat on the queue for
     * six hours and then failed has made one attempt, not one per hour it waited.
     *
     * @param int $maxTries Attempts allowed in total, from the #[Job] attribute or config.
     */
    public function isFinalAttempt(int $maxTries): bool
    {
        return $this->attempts >= $maxTries;
    }

    /**
     * Every field in the shape a store would persist, with keys in column style.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'queue' => $this->queue,
            'job_class' => $this->jobClass,
            'payload' => $this->payload,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'available_at' => $this->availableAt,
            'reserved_at' => $this->reservedAt,
            'reserved_until' => $this->reservedUntil,
            'reserved_by' => $this->reservedBy,
            'completed_at' => $this->completedAt,
            'created_at' => $this->createdAt,
        ];
    }
}