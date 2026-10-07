<?php

declare(strict_types=1);

namespace Phue\Tests\Queue;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Database\Connection;
use Phue\Modules\Queue\Driver\DatabaseQueueDriver;
use Phue\Modules\Queue\Exception\NonRetryableException;
use Phue\Modules\Queue\Exception\SchemaException;
use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;
use Phue\Modules\Queue\QueueConfig;
use Phue\Modules\Queue\QueueManager;
use Phue\Modules\Queue\Schema\QueueSchema;
use Phue\Tests\Queue\Stubs\AttemptCountingJob;
use Phue\Tests\Support\SqliteFactory;

/**
 * The retry lifecycle against a real table, where the atomicity claims can actually be tested.
 *
 * The memory driver proves the manager makes the right decisions. This proves the SQL underneath
 * enforces them — and SQL is where an apparently correct implementation quietly is not, because a
 * claim that is two statements instead of one is indistinguishable from a correct one until two
 * workers exist.
 *
 * @package Phue\Tests\Queue
 */
#[Group('unit')]
final class DatabaseQueueLifecycleTest extends TestCase
{
    private Connection $connection;

    private QueueConfig $config;

    private DatabaseQueueDriver $driver;

    private QueueManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = SqliteFactory::memory();
        $this->config = QueueConfig::fromArray([
            'driver' => 'database',
            'backoff_jitter' => false,
            'backoff_base' => 0,
            'max_tries' => 3,
        ]);
        (new QueueSchema($this->connection, $this->config->table))->install();

        $this->driver = new DatabaseQueueDriver($this->connection, $this->config);
        $this->manager = new QueueManager($this->driver, $this->config);
    }

    // ------------------------------------------------------------------ deferral

    /**
     * The whole reason for a second driver: pushing does not run the job, so something else has to.
     */
    public function testPushingStoresAJobWithoutRunningIt(): void
    {
        AttemptCountingJob::$runs = 0;

        $this->manager->push(new AttemptCountingJob());

        $this->assertSame(0, AttemptCountingJob::$runs);
        $this->assertSame(1, $this->driver->stats()['pending']);
    }

    public function testTheDriverCanHoldAJobBackInTime(): void
    {
        $this->assertTrue($this->driver->supportsDelayed());
    }

    public function testADelayedJobIsNotClaimableUntilItIsDue(): void
    {
        $id = $this->manager->later(new AttemptCountingJob(), 60);

        $this->assertNull($this->manager->reserve('default', 'w1'), 'not due yet');
        $this->assertNull($this->manager->reserve('default', 'w1'), 'still not due');
        $this->assertSame(1, $this->driver->stats()['pending']);
        $this->assertNotSame('', $id);
    }

    public function testADelayedJobIsClaimableOnceItIsDue(): void
    {
        $this->manager->later(new AttemptCountingJob(), 5, 'later-queue');

        $this->assertNull($this->manager->reserve('later-queue', 'w1'));
        $this->assertNotNull(
            $this->thisDriverReserveWhenDue('later-queue'),
            'the job exists and becomes claimable when due'
        );
    }

    // ------------------------------------------------------------------ the retry lifecycle

    public function testASuccessfulJobCompletesAndLeavesThePendingList(): void
    {
        AttemptCountingJob::$runs = 0;
        $this->manager->push(new AttemptCountingJob());

        $this->assertTrue($this->manager->runNext('default', 'w1'));

        $this->assertSame(1, AttemptCountingJob::$runs);
        $this->assertSame(1, $this->driver->stats()['completed']);
        $this->assertSame(0, $this->driver->stats()['pending']);
    }

    /**
     * The core of the whole design, on a real table: three attempts, then dead-lettered, and the
     * attempt counter is the one the claim statement wrote rather than the one PHP remembered.
     */
    public function testAJobIsRetriedUntilItsAttemptsRunOut(): void
    {
        $this->manager->push(new \Phue\Tests\Queue\Stubs\ThrowingJob('boom'));

        $attempts = [];

        for ($run = 1; $run <= 3; $run++) {
            $envelope = $this->driver->reserve(null, 'w1', 60, $this->clock());
            $this->assertNotNull($envelope, 'attempt ' . $run . ' should be claimable');
            $attempts[] = $envelope->attempts;
            $this->manager->run($envelope, 'w1');
        }


        $this->assertSame([1, 2, 3], $attempts);
        $this->assertSame(0, $this->driver->stats()['pending']);
        $this->assertSame(1, $this->driver->stats()['failed']);
        $this->assertNull($this->driver->reserve(null, 'w1', 60, $this->clock()), 'no fourth attempt');
    }

    public function testTheAttemptCountSurvivesInTheTable(): void
    {
        $this->manager->push(new \Phue\Tests\Queue\Stubs\ThrowingJob());

        for ($run = 1; $run <= 2; $run++) {
            $envelope = $this->driver->reserve(null, 'w1', 60, $this->clock());
            $this->manager->run($envelope, 'w1');
        }

        $rows = (array) $this->connection->query(
            'SELECT attempts FROM queue_jobs WHERE status = ?',
            [\Phue\Modules\Queue\JobStatus::Pending->value]
        )->fetchAll();

        $this->assertCount(1, $rows);
        $this->assertSame('2', (string) ((array) $rows[0])['attempts']);
    }

    public function testAFailureMarkedNonRetryableSkipsTheRemainingAttempts(): void
    {
        $this->manager->push(new \Phue\Tests\Queue\Stubs\NonRetryableJob());

        $envelope = $this->driver->reserve(null, 'w1', 60, $this->clock());
        $this->assertNotNull($envelope);
        $this->manager->run($envelope, 'w1');

        $this->assertSame(0, $this->driver->stats()['pending']);
        $this->assertSame(1, $this->driver->stats()['failed']);
    }

    public function testTheDeadLetterRecordCarriesThePayloadSoTheJobCanBeReplayed(): void
    {
        $this->manager->push(new \Phue\Tests\Queue\Stubs\ThrowingJob('the reason'));

        // Run it out of attempts, since a job inside its limit is released rather than recorded.
        for ($run = 1; $run <= 3; $run++) {
            $envelope = $this->driver->reserve(null, 'w1', 60, $this->clock());
            $this->manager->run($envelope, 'w1');
        }

        $rows = (array) $this->connection->query('SELECT * FROM queue_jobs_failed')->fetchAll();

        $this->assertCount(1, $rows);
        $row = (array) $rows[0];

        $this->assertStringContainsString('the reason', (string) $row['exception']);
        $this->assertStringContainsString('terminal', (string) $row['exception']);
        $this->assertSame(\Phue\Tests\Queue\Stubs\ThrowingJob::class, (string) $row['job_class']);
        $this->assertNotSame('', (string) $row['payload']);
        $this->assertSame('3', (string) $row['attempts'], 'the record says how many attempts it took');
    }

    // ------------------------------------------------------------------ leases

    /**
     * A claim that was two statements instead of one would pass every other test here and lose a
     * job under concurrency. This is the test for it: one job, two claims, and exactly one winner.
     */
    public function testTwoWorkersCannotClaimTheSameJob(): void
    {
        $this->manager->push(new AttemptCountingJob());

        $first = $this->driver->reserve(null, 'w1', 60, $this->clock());
        $second = $this->driver->reserve(null, 'w2', 60, $this->clock());

        $this->assertNotNull($first);
        $this->assertNull($second, 'a claimed job must not be handed out twice');
    }

    /**
     * The reason for the retry loop inside reserve(): the worker that loses the race should get
     * the *next* job, not an empty queue.
     */
    public function testASecondWorkerSkipsToTheNextJobRatherThanReportingAnEmptyQueue(): void
    {
        $this->manager->push(new AttemptCountingJob());
        $this->manager->push(new AttemptCountingJob());

        $first = $this->driver->reserve(null, 'w1', 60, $this->clock());
        $second = $this->driver->reserve(null, 'w2', 60, $this->clock());

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first->id, $second->id);
    }

    public function testAJobWhoseLeaseExpiredIsClaimableByAnotherWorker(): void
    {
        $this->manager->push(new AttemptCountingJob());

        $now = $this->clock();
        $held = $this->driver->reserve(null, 'w1', 60, $now);
        $this->assertNotNull($held);

        $reclaimed = $this->driver->reserve(null, 'w2', 60, $now + 61);

        $this->assertNotNull($reclaimed, 'an expired lease must not strand the job');
        $this->assertSame($held->id, $reclaimed->id);
        $this->assertSame('w2', $reclaimed->reservedBy);
        $this->assertSame(2, $reclaimed->attempts, 'a reclaimed job is a further attempt');
    }

    /**
     * The property the lease exists for, stated as an outcome rather than as a mechanism.
     */
    public function testAJobIsStillThereAfterTheWorkerThatHadItStopsExisting(): void
    {
        $this->manager->push(new AttemptCountingJob());
        $now = $this->clock();
        $this->driver->reserve(null, 'w-dies', 60, $now);

        // No completion, no release: the worker simply never comes back.
        $this->assertNotNull($this->driver->reserve(null, 'w-next', 60, $now + 3600));
    }

    public function testAWorkerThatLostItsLeaseCannotAcknowledgeTheJob(): void
    {
        $this->manager->push(new AttemptCountingJob());
        $now = $this->clock();
        $stale = $this->driver->reserve(null, 'w1', 60, $now);
        $this->driver->reserve(null, 'w2', 60, $now + 61);

        $this->assertFalse($this->driver->complete($stale, 'w1'));
        $this->assertFalse($this->driver->release($stale, 'w1', 0));
    }

    // ------------------------------------------------------------------ reaper

    public function testTheReaperReturnsAnExpiredLeaseToThePendingList(): void
    {
        $this->manager->push(new AttemptCountingJob());
        $now = $this->clock();
        $this->driver->reserve(null, 'w1', 60, $now);

        $reclaimed = $this->driver->reclaimExpiredLeases(0, $now + 61);

        $this->assertSame(1, $reclaimed);
        $this->assertSame(1, $this->driver->stats()['pending']);
        $this->assertSame(0, $this->driver->stats()['reserved']);
    }

    /**
     * The grace period is what stops a slow job being taken away from a worker still running it.
     */
    public function testTheReaperLeavesALeaseAloneUntilTheGraceHasPassed(): void
    {
        $this->manager->push(new AttemptCountingJob());
        $now = $this->clock();
        $this->driver->reserve(null, 'w1', 60, $now);

        $this->assertSame(0, $this->driver->reclaimExpiredLeases(600, $now + 61));
        $this->assertSame(0, $this->driver->reclaimExpiredLeases(600, $now + 600));
        $this->assertSame(1, $this->driver->reclaimExpiredLeases(600, $now + 661));
    }

    public function testTheReaperLeavesUnfinishedWorkAlone(): void
    {
        $this->manager->push(new AttemptCountingJob());

        $this->assertSame(0, $this->driver->reclaimExpiredLeases(0, $this->clock() + 100_000));
        $this->assertSame(1, $this->driver->stats()['pending']);
    }

    /**
     * The manager reports zero on a driver that has no leases, rather than pretending it swept.
     */
    public function testTheManagerReportsNothingToReclaimOnADriverWithoutLeases(): void
    {
        $inline = new \Phue\Modules\Queue\Driver\SyncQueueDriver();
        $manager = new QueueManager($inline, QueueConfig::fromArray([]));

        $this->assertSame(0, $manager->reclaimExpiredLeases());
    }

    // ------------------------------------------------------------------ pruning

    public function testPruningRemovesCompletedJobsPastTheAge(): void
    {
        $this->manager->push(new AttemptCountingJob());
        $this->manager->runNext('default', 'w1');

        $this->assertSame(0, $this->driver->prune(3600));
        $this->assertSame(1, $this->driver->prune(0));
        $this->assertSame(0, $this->driver->stats()['completed']);
    }

    /**
     * A failure record is the only evidence something went wrong, so it outlives the completed jobs
     * beside it by an order of magnitude.
     */
    public function testPruningKeepsFailuresFarLongerThanCompletedJobs(): void
    {
        $this->manager->push(new \Phue\Tests\Queue\Stubs\NonRetryableJob());
        $envelope = $this->driver->reserve(null, 'w1', 60, $this->clock());
        $this->assertNotNull($envelope);
        $this->manager->run($envelope, 'w1');

        $this->driver->prune(0);

        $this->assertSame(1, $this->driver->stats()['failed'], 'the failure is kept');
    }

    public function testPruningNeverTouchesPendingOrReservedWork(): void
    {
        $this->manager->push(new AttemptCountingJob());
        $this->driver->reserve(null, 'w1', 60, $this->clock());

        $this->assertSame(0, $this->driver->prune(0));
        $this->assertSame(1, $this->driver->stats()['reserved']);
    }

    // ------------------------------------------------------------------ isolation

    public function testQueuesAreIndependent(): void
    {
        $this->manager->push(new AttemptCountingJob(), 'emails');
        $this->manager->push(new AttemptCountingJob(), 'reports');

        $this->assertNotNull($this->manager->reserve('emails', 'w1'));
        $this->assertSame(1, $this->driver->stats('emails')['reserved']);
        $this->assertSame(0, $this->driver->stats('reports')['reserved']);
        $this->assertSame(1, $this->driver->stats('reports')['pending']);
    }

    public function testOneQueueCanBeDrainedWithoutTouchingAnother(): void
    {
        $this->manager->push(new AttemptCountingJob(), 'emails');
        $this->manager->push(new AttemptCountingJob(), 'emails');
        $this->manager->push(new AttemptCountingJob(), 'reports');

        $this->assertTrue($this->manager->runNext('emails', 'w1'));
        $this->assertTrue($this->manager->runNext('emails', 'w1'));
        $this->assertFalse($this->manager->runNext('emails', 'w1'));
        $this->assertTrue($this->manager->runNext('reports', 'w1'));
    }

    // ------------------------------------------------------------------ payload round trip

    /**
     * Values have to survive being written as JSON and read back, including the characters that
     * break a naive encoder.
     */
    public function testAPayloadSurvivesTheRoundTripThroughTheTable(): void
    {
        $this->manager->push(new \Phue\Tests\Queue\Stubs\PlainJob('ünïcödé "quoted" / sl\\ash', -42));

        $envelope = $this->manager->reserve('default', 'w1');
        $job = $this->manager->hydrate($envelope);

        $this->assertInstanceOf(\Phue\Tests\Queue\Stubs\PlainJob::class, $job);
        $this->assertSame('ünïcödé "quoted" / sl\\ash', $job->subject);
        $this->assertSame(-42, $job->count);
    }

    public function testAnEmptyPayloadSurvivesTheRoundTrip(): void
    {
        $this->manager->push(new \Phue\Tests\Queue\Stubs\NoConstructorJob());

        $envelope = $this->manager->reserve('default', 'w1');

        $this->assertSame([], $envelope->payload);
        $this->assertInstanceOf(
            \Phue\Tests\Queue\Stubs\NoConstructorJob::class,
            $this->manager->hydrate($envelope)
        );
    }

    // ------------------------------------------------------------------ unusable rows

    /**
     * A payload naming a class that is not there must be recorded, not merely refused.
     *
     * Found by running the real CLI: the manager read the job's #[Job] attribute — which means
     * reflecting on a name out of storage — before checking the class existed, so a missing one threw
     * a ReflectionException from inside the framework. That escaped every catch in the method and
     * left the job reserved forever, with no worker accounting for it and nothing in the logs.
     */
    public function testAJobNamingAClassThatIsGoneIsDeadLetteredRatherThanStranded(): void
    {
        $driver = new DatabaseQueueDriver($this->connection, $this->config);
        $id = $driver->push(\Phue\Modules\Queue\JobEnvelope::forJob(
            'App\\Jobs\\RetiredJob',
            ['a' => 1],
            'default'
        ));

        $this->assertNotSame('', $id, 'the driver stores whatever it is handed');

        $envelope = $driver->reserve(null, 'w1', 60, $this->clock());
        $this->assertNotNull($envelope);

        $outcome = $this->manager->run($envelope, 'w1');

        $this->assertSame(\Phue\Modules\Queue\JobOutcome::Failed, $outcome);
        $this->assertSame(0, $this->driver->stats()['reserved'], 'nothing left stranded');
        $this->assertSame(1, $this->driver->stats()['failed']);
        $this->assertNull(
            $driver->reserve(null, 'w1', 60, $this->clock()),
            'and nothing left pending either'
        );
    }

    /**
     * The message has to name the class, because the fix is a rename or a deploy and an operator
     * cannot guess which.
     */
    public function testTheDeadLetterForAMissingClassNamesIt(): void
    {
        $driver = new DatabaseQueueDriver($this->connection, $this->config);
        $driver->push(\Phue\Modules\Queue\JobEnvelope::forJob('App\\Jobs\\RetiredJob', [], 'default'));
        $this->manager->run($driver->reserve(null, 'w1', 60, $this->clock()), 'w1');

        $rows = (array) $this->connection->query('SELECT exception FROM queue_jobs_failed')->fetchAll();

        $this->assertStringContainsString('RetiredJob', (string) ((array) $rows[0])['exception']);
        $this->assertStringContainsString(
            'does not exist or is not autoloadable',
            (string) ((array) $rows[0])['exception']
        );
    }

    /**
     * A job whose class exists but is not a job is the same failure with a different cause, and
     * equally must not strand anything.
     */
    public function testAJobNamingAClassThatIsNotAJobIsAlsoRecorded(): void
    {
        $driver = new DatabaseQueueDriver($this->connection, $this->config);
        $driver->push(\Phue\Modules\Queue\JobEnvelope::forJob(\stdClass::class, [], 'default'));

        $outcome = $this->manager->run($driver->reserve(null, 'w1', 60, $this->clock()), 'w1');

        $this->assertSame(\Phue\Modules\Queue\JobOutcome::Failed, $outcome);
        $this->assertSame(0, $this->driver->stats()['reserved']);
        $this->assertSame(1, $this->driver->stats()['failed']);
    }

    /**
     * Whatever goes wrong, the job is accounted for. A bug in the framework is not a reason for a
     * job to disappear into a reservation nothing will ever come back for.
     */
    public function testAJobOutsideTheAllowedNamespacesIsRecordedNotStranded(): void
    {
        $config = QueueConfig::fromArray([
            'driver' => 'database',
            'backoff_jitter' => false,
            'job_namespaces' => ['App\\Jobs'],
        ]);
        $manager = new QueueManager(new DatabaseQueueDriver($this->connection, $config), $config);
        $driver = new DatabaseQueueDriver($this->connection, $config);

        $driver->push(\Phue\Modules\Queue\JobEnvelope::forJob(
            \Phue\Tests\Queue\Stubs\PlainJob::class,
            [],
            'default'
        ));

        $outcome = $manager->run($driver->reserve(null, 'w1', 60, $this->clock()), 'w1');

        $this->assertSame(\Phue\Modules\Queue\JobOutcome::Failed, $outcome);
        $this->assertSame(0, $driver->stats()['reserved']);
        $this->assertSame(1, $driver->stats()['failed']);
    }

    // ------------------------------------------------------------------ refusals

    public function testADriverAgainstAMissingTableRefusesEveryOperation(): void
    {
        $driver = new DatabaseQueueDriver(SqliteFactory::memory(), $this->config);
        $envelope = \Phue\Modules\Queue\JobEnvelope::forJob('Some\\Job', [], 'default');

        foreach ([
            static fn() => $driver->stats(),
            static fn() => $driver->reserve(null, 'w1', 60),
            static fn() => $driver->complete($envelope, 'w1'),
            static fn() => $driver->release($envelope, 'w1', 0),
            static fn() => $driver->fail($envelope, 'w1', new \RuntimeException('x'), false),
            static fn() => $driver->prune(0),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected a SchemaException.');
            } catch (SchemaException $e) {
                $this->assertStringContainsString('queue:install', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------ helpers

    private function clock(): int
    {
        return time();
    }

    /**
     * A claim far enough ahead that a job queued "now" is due.
     */
    private function thisDriverReserveWhenDue(string $queue): ?\Phue\Modules\Queue\JobEnvelope
    {
        return $this->driver->reserve($queue, 'w1', 60, time() + 120);
    }
}
