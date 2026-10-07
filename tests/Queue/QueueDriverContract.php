<?php

declare(strict_types=1);

namespace Phue\Tests\Queue;

use PHPUnit\Framework\TestCase;
use Phue\Modules\Queue\ExecutionModel;
use Phue\Modules\Queue\JobEnvelope;
use Phue\Modules\Queue\QueueDriverInterface;

/**
 * The behaviour every queue driver owes its callers, regardless of where the bytes land.
 *
 * Abstract on purpose. The next driver to be written — database, then Redis — extends this and
 * gets its correctness properties tested for free, and the moment it diverges the failure is
 * reported as "this driver broke a rule the queue relies on" rather than as a bug report from
 * whatever the divergence happened to break first.
 *
 * The properties are stated here rather than derived from the interface on purpose. An interface
 * says which methods exist; it says nothing about which of them must refuse to act on a job the
 * caller no longer owns, and that is the part that goes wrong quietly.
 *
 * @package Phue\Tests\Queue
 */
abstract class QueueDriverContract extends TestCase
{
    /**
     * The driver under test, already constructed.
     */
    abstract protected function driver(): QueueDriverInterface;

    /**
     * A fresh driver per test.
     *
     * Each test gets its own because every assertion here is about state left behind by a
     * previous operation, and a shared instance turns the second test into a test of the first.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->driver();
    }

    /** @var QueueDriverInterface */
    protected QueueDriverInterface $driver;

    // ------------------------------------------------------------------ capability reporting

    public function testTheDriverNamesItself(): void
    {
        $this->assertNotSame('', $this->driver->name());
    }

    /**
     * A driver that runs jobs as it pushes them cannot also wait for jobs, because by the time
     * push() returns the work is already gone. The two capabilities contradict each other, and a
     * driver claiming both would make the manager either wait for a job that will never arrive
     * or leave one behind for a worker that does not exist.
     */
    public function testAnInlineDriverNeverBlocks(): void
    {
        $this->assertFalse(
            $this->driver->executionModel() === ExecutionModel::Inline && $this->driver->supportsBlocking(),
            'an inline driver has nothing to block on'
        );
    }

    /**
     * A driver that cannot hold a job until a future time has to say no to a delayed push. The
     * manager relies on this to refuse rather than run the job immediately, which is the whole
     * reason an inline driver must answer false here.
     */
    public function testDelayedSupportIsReportedHonestly(): void
    {
        $this->assertFalse(
            $this->driver->executionModel() === ExecutionModel::Inline && $this->driver->supportsDelayed(),
            'an inline driver cannot keep a job until a future time'
        );
    }

    // ------------------------------------------------------------------ push and reserve

    public function testAPushedJobIsGivenAnId(): void
    {
        $id = $this->driver->push($this->envelope());

        $this->assertNotSame('', $id);
    }

    public function testEachPushedJobGetsItsOwnId(): void
    {
        $first = $this->driver->push($this->envelope());
        $second = $this->driver->push($this->envelope());

        $this->assertNotSame($first, $second);
    }

    public function testAnEmptyQueueReservesNothing(): void
    {
        $this->assertNull($this->driver->reserve(null, 'worker-1', 60));
    }

    public function testAReservedJobCarriesItsIdBack(): void
    {
        $id = $this->driver->push($this->envelope());

        $reserved = $this->driver->reserve(null, 'worker-1', 60);

        $this->assertNotNull($reserved);
        $this->assertSame($id, $reserved->id);
    }

    public function testAReservedJobIsOwnedByTheWorkerThatClaimedIt(): void
    {
        $this->driver->push($this->envelope());

        $reserved = $this->driver->reserve(null, 'worker-1', 60);

        $this->assertSame('worker-1', $reserved?->reservedBy);
    }

    /**
     * The number that drives every retry decision is the count of attempts actually made, not the
     * length of the wait. A job that sat for six hours and failed on its first run has made one
     * attempt.
     */
    public function testReservingCountsTheAttempt(): void
    {
        $this->driver->push($this->envelope());

        $this->assertSame(1, $this->driver->reserve(null, 'worker-1', 60)?->attempts);
    }

    public function testAReservedJobIsNotHandedOutTwice(): void
    {
        $this->driver->push($this->envelope());

        $this->driver->reserve(null, 'worker-1', 60);

        $this->assertNull($this->driver->reserve(null, 'worker-2', 60));
    }

    public function testAReservedJobIsInvisibleToASecondWorkerUntilItsLeaseEnds(): void
    {
        $this->driver->push($this->envelope());
        $this->driver->reserve(null, 'worker-1', 60, $now = 1_000);

        $this->assertNull($this->driver->reserve(null, 'worker-2', 60, $now + 59));
    }

    /**
     * This is the property the whole lease design exists for. Nothing observes a worker dying, so
     * the lease running out is the only way stranded work comes back. If a driver cannot reclaim
     * an expired reservation, a `kill -9` loses the job permanently.
     */
    public function testAJobWhoseLeaseExpiredBecomesClaimableAgain(): void
    {
        $this->driver->push($this->envelope());
        $this->driver->reserve(null, 'worker-1', 60, $now = 1_000);

        $reclaimed = $this->driver->reserve(null, 'worker-2', 60, $now + 61);

        $this->assertNotNull($reclaimed, 'an expired lease must not strand the job');
        $this->assertSame('worker-2', $reclaimed->reservedBy);
    }

    public function testReservingIsLimitedToTheRequestedQueue(): void
    {
        $this->driver->push($this->envelope(queue: 'emails'));

        $this->assertNull($this->driver->reserve('reports', 'worker-1', 60));
        $this->assertNotNull($this->driver->reserve('emails', 'worker-1', 60));
    }

    public function testReservingWithoutAQueueTakesAnyQueue(): void
    {
        $this->driver->push($this->envelope(queue: 'emails'));
        $this->driver->push($this->envelope(queue: 'reports'));

        $this->driver->reserve(null, 'worker-1', 60);
        $this->driver->reserve(null, 'worker-1', 60);

        $this->assertNull($this->driver->reserve(null, 'worker-1', 60));
    }

    public function testAJobThatIsNotDueYetIsNotClaimable(): void
    {
        $this->driver->push($this->envelope(availableAt: 2_000));

        $this->assertNull($this->driver->reserve(null, 'worker-1', 60, 1_999));
        $this->assertNotNull($this->driver->reserve(null, 'worker-1', 60, 2_000));
    }

    public function testJobsAreClaimedInTheOrderTheyBecameDue(): void
    {
        $later = $this->driver->push($this->envelope(jobClass: 'Later', availableAt: 2_000));
        $earlier = $this->driver->push($this->envelope(jobClass: 'Earlier', availableAt: 1_500));

        $this->assertSame($earlier, $this->driver->reserve(null, 'worker-1', 60, 5_000)?->id);
        $this->assertSame($later, $this->driver->reserve(null, 'worker-1', 60, 5_000)?->id);
    }

    // ------------------------------------------------------------------ the ownership guard

    /**
     * The single most important property in this file.
     *
     * A worker whose lease expired mid-job still holds the envelope it was given. If completing
     * that envelope succeeded, the job would be marked done by a worker that may not have done it,
     * while another worker is running it right now. The answer has to be false.
     */
    public function testCompletingAJobBelongingToAnotherWorkerIsRefused(): void
    {
        $this->driver->push($this->envelope());
        $reserved = $this->driver->reserve(null, 'worker-1', 60, 1_000);

        // worker-1's lease expires and worker-2 takes over.
        $this->driver->reserve(null, 'worker-2', 60, 1_061);

        $this->assertFalse(
            $this->driver->complete($reserved, 'worker-1'),
            'a worker that lost its lease must not be able to acknowledge the job'
        );
    }

    public function testReleasingAJobBelongingToAnotherWorkerIsRefused(): void
    {
        $this->driver->push($this->envelope());
        $reserved = $this->driver->reserve(null, 'worker-1', 60, 1_000);
        $this->driver->reserve(null, 'worker-2', 60, 1_061);

        $this->assertFalse($this->driver->release($reserved, 'worker-1', 0));
    }

    public function testFailingAJobBelongingToAnotherWorkerIsRefused(): void
    {
        $this->driver->push($this->envelope());
        $reserved = $this->driver->reserve(null, 'worker-1', 60, 1_000);
        $this->driver->reserve(null, 'worker-2', 60, 1_061);

        $this->assertFalse($this->driver->fail($reserved, 'worker-1', new \RuntimeException('x'), false));
    }

    public function testAJobThatIsNotReservedCannotBeCompleted(): void
    {
        $id = $this->driver->push($this->envelope());

        $pending = new JobEnvelope(
            id: $id,
            queue: 'default',
            jobClass: 'Some\\Job',
            payload: [],
            status: \Phue\Modules\Queue\JobStatus::Pending,
            attempts: 0,
            availableAt: 0,
        );

        $this->assertFalse($this->driver->complete($pending, 'worker-1'));
    }

    public function testAJobThatDoesNotExistCannotBeCompleted(): void
    {
        $ghost = new JobEnvelope(
            id: 'no-such-id',
            queue: 'default',
            jobClass: 'Some\\Job',
            payload: [],
            status: \Phue\Modules\Queue\JobStatus::Reserved,
            attempts: 1,
            availableAt: 0,
            reservedBy: 'worker-1',
        );

        $this->assertFalse($this->driver->complete($ghost, 'worker-1'));
    }

    // ------------------------------------------------------------------ finishing a job

    public function testCompletingAJobTakesItOutOfCirculation(): void
    {
        $this->driver->push($this->envelope());
        $reserved = $this->driver->reserve(null, 'worker-1', 60);

        $this->assertTrue($this->driver->complete($reserved, 'worker-1'));
        $this->assertNull($this->driver->reserve(null, 'worker-2', 60));
    }

    public function testReleasingAJobPutsItBackWithinTheDelay(): void
    {
        $id = $this->driver->push($this->envelope(availableAt: 1_000));
        $reserved = $this->driver->reserve(null, 'worker-1', 60, 1_000);

        $this->assertTrue($this->driver->release($reserved, 'worker-1', 30, 1_000));
        $this->assertNull($this->driver->reserve(null, 'worker-2', 60, 1_029));
        $this->assertSame($id, $this->driver->reserve(null, 'worker-2', 60, 1_030)?->id);
    }

    public function testAReleasedJobIsCountedAsAFreshAttempt(): void
    {
        $this->driver->push($this->envelope());
        $reserved = $this->driver->reserve(null, 'worker-1', 60);
        $this->driver->release($reserved, 'worker-1', 0);

        $this->assertSame(2, $this->driver->reserve(null, 'worker-1', 60)?->attempts);
    }

    public function testFailingAJobRemovesIt(): void
    {
        $this->driver->push($this->envelope());
        $reserved = $this->driver->reserve(null, 'worker-1', 60);

        $this->assertTrue($this->driver->fail($reserved, 'worker-1', new \RuntimeException('x'), false));
        $this->assertNull($this->driver->reserve(null, 'worker-2', 60));
    }

    // ------------------------------------------------------------------ reporting

    public function testStatsOnAnEmptyQueueAreAllZero(): void
    {
        $stats = $this->driver->stats();

        foreach (['pending', 'reserved', 'completed', 'failed', 'total'] as $key) {
            $this->assertArrayHasKey($key, $stats);
            $this->assertSame(0, $stats[$key], $key);
        }
    }

    public function testStatsCountAPendingJob(): void
    {
        $this->driver->push($this->envelope());

        $this->assertSame(1, $this->driver->stats()['pending']);
    }

    public function testStatsCountAReservedJob(): void
    {
        $this->driver->push($this->envelope());
        $this->driver->reserve(null, 'worker-1', 60);

        $this->assertSame(1, $this->driver->stats()['reserved']);
        $this->assertSame(0, $this->driver->stats()['pending']);
    }

    public function testStatsArePerQueueWhenAQueueIsNamed(): void
    {
        $this->driver->push($this->envelope(queue: 'emails'));
        $this->driver->push($this->envelope(queue: 'reports'));

        $this->assertSame(1, $this->driver->stats('emails')['pending']);
        $this->assertSame(1, $this->driver->stats('reports')['pending']);
        $this->assertSame(2, $this->driver->stats()['pending']);
    }

    public function testPruningKeepsRecentCompletedJobs(): void
    {
        $this->driver->push($this->envelope());
        $reserved = $this->driver->reserve(null, 'worker-1', 60);
        $this->driver->complete($reserved, 'worker-1');

        $this->assertSame(0, $this->driver->prune(3600));
        $this->assertSame(1, $this->driver->stats()['completed']);
    }

    public function testPruningForgetsCompletedJobsOlderThanTheGivenAge(): void
    {
        $this->driver->push($this->envelope());
        $reserved = $this->driver->reserve(null, 'worker-1', 60);
        $this->driver->complete($reserved, 'worker-1');

        $this->assertSame(1, $this->driver->prune(0));
        $this->assertSame(0, $this->driver->stats()['completed']);
    }

    public function testPruningNeverTouchesPendingWork(): void
    {
        $this->driver->push($this->envelope());

        $this->assertSame(0, $this->driver->prune(0));
        $this->assertSame(1, $this->driver->stats()['pending']);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A stored job, with no id and no reservation, as push() expects to receive it.
     *
     * Due immediately by default, so a test that injects a clock does not have to keep the two in
     * step just to get past the availability check.
     */
    protected function envelope(
        string $queue = 'default',
        string $jobClass = 'Phue\\Tests\\Queue\\Stubs\\PlainJob',
        int $availableAt = 0
    ): JobEnvelope {
        return JobEnvelope::forJob(
            jobClass: $jobClass,
            payload: ['subject' => 'x', 'count' => 0],
            queue: $queue,
            availableAt: $availableAt
        );
    }
}