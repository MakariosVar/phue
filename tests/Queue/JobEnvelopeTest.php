<?php

declare(strict_types=1);

namespace Phue\Tests\Queue;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Modules\Queue\JobEnvelope;
use Phue\Modules\Queue\JobStatus;
use Phue\Tests\Queue\Stubs\PlainJob;

/**
 * The bookkeeping a store keeps about a job.
 *
 * @package Phue\Tests\Queue
 */
#[Group('unit')]
final class JobEnvelopeTest extends TestCase
{
    public function testANewJobIsPendingAndDueImmediately(): void
    {
        $envelope = JobEnvelope::forJob(PlainJob::class, [], 'default', 1_000);

        $this->assertSame(JobStatus::Pending, $envelope->status);
        $this->assertSame(0, $envelope->attempts);
        $this->assertSame(1_000, $envelope->availableAt);
        $this->assertSame('', $envelope->id);
    }

    public function testReservingRecordsTheOwnerAndTheLeaseAndCountsTheAttempt(): void
    {
        $reserved = JobEnvelope::forJob(PlainJob::class, [], 'default', 1_000)->reserved('w1', 1_000, 1_060);

        $this->assertSame(JobStatus::Reserved, $reserved->status);
        $this->assertSame('w1', $reserved->reservedBy);
        $this->assertSame(1_060, $reserved->reservedUntil);
        $this->assertSame(1, $reserved->attempts);
    }

    public function testEveryReservationCountsAgain(): void
    {
        $envelope = JobEnvelope::forJob(PlainJob::class, [], 'default', 1_000)
            ->reserved('w1', 1_000, 1_060)
            ->pendingFrom(1_001)
            ->reserved('w2', 1_001, 1_061);

        $this->assertSame(2, $envelope->attempts);
    }

    /**
     * The number that decides whether another attempt happens. Counting at push time instead would
     * make a job that waited six hours look like it had tried six times.
     */
    public function testTheFinalAttemptIsTheOneThatReachesTheLimit(): void
    {
        $envelope = JobEnvelope::forJob(PlainJob::class, [], 'default', 1_000)->reserved('w1', 1_000, 1_060);

        $this->assertFalse($envelope->isFinalAttempt(3));
        $this->assertTrue($envelope->isFinalAttempt(1));
    }

    public function testAJobIsNotItsOwnFinalAttemptAtZero(): void
    {
        $envelope = JobEnvelope::forJob(PlainJob::class, [], 'default', 1_000);

        $this->assertFalse($envelope->isFinalAttempt(1));
    }

    public function testALeaseHasNotExpiredBeforeItsEnd(): void
    {
        $reserved = JobEnvelope::forJob(PlainJob::class, [], 'default', 1_000)->reserved('w1', 1_000, 1_060);

        $this->assertFalse($reserved->leaseExpired(1_000));
        $this->assertFalse($reserved->leaseExpired(1_059));
    }

    /**
     * The whole reason the lease exists: nobody observes a worker dying, so the lease running out
     * is the only thing that brings its work back.
     */
    public function testALeaseHasExpiredAtItsEnd(): void
    {
        $reserved = JobEnvelope::forJob(PlainJob::class, [], 'default', 1_000)->reserved('w1', 1_000, 1_060);

        $this->assertTrue($reserved->leaseExpired(1_060));
        $this->assertTrue($reserved->leaseExpired(99_999));
    }

    public function testAPendingJobHasNoLeaseToExpire(): void
    {
        $this->assertFalse(JobEnvelope::forJob(PlainJob::class, [], 'default')->leaseExpired(9_999));
    }

    public function testCompletingClearsTheOwner(): void
    {
        $completed = JobEnvelope::forJob(PlainJob::class, [], 'default', 1_000)
            ->reserved('w1', 1_000, 1_060)
            ->completedAt(1_001);

        $this->assertSame(JobStatus::Completed, $completed->status);
        $this->assertNull($completed->reservedBy);
        $this->assertNull($completed->reservedUntil);
        $this->assertSame(1, $completed->attempts);
    }

    public function testGoingBackToPendingKeepsTheAttemptCount(): void
    {
        $requeued = JobEnvelope::forJob(PlainJob::class, [], 'default', 1_000)
            ->reserved('w1', 1_000, 1_060)
            ->pendingFrom(1_001, 1_031);

        $this->assertSame(JobStatus::Pending, $requeued->status);
        $this->assertSame(1, $requeued->attempts, 'a release does not make the attempt untrue');
        $this->assertSame(1_031, $requeued->availableAt);
        $this->assertNull($requeued->reservedBy);
    }

    public function testTheArrayFormCarriesEverything(): void
    {
        $row = JobEnvelope::forJob(PlainJob::class, ['a' => 1], 'emails', 1_000)
            ->reserved('w1', 1_000, 1_060)
            ->toArray();

        $this->assertSame('emails', $row['queue']);
        $this->assertSame('reserved', $row['status']);
        $this->assertSame('w1', $row['reserved_by']);
        $this->assertSame(['a' => 1], $row['payload']);
    }

    /**
     * Immutability is what stops a completed job being completed again by a stale reference, so
     * every transition has to produce a new value rather than edit one.
     */
    public function testTransitionsDoNotMutateTheOriginal(): void
    {
        $original = JobEnvelope::forJob(PlainJob::class, [], 'default', 1_000);

        $original->reserved('w1', 1_000, 1_060);
        $original->pendingFrom(1_001);

        $this->assertSame(JobStatus::Pending, $original->status);
        $this->assertSame(0, $original->attempts);
        $this->assertNull($original->reservedBy);
    }
}