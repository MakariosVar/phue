<?php

declare(strict_types=1);

namespace Phue\Tests\Queue;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Modules\Queue\Driver\SyncQueueDriver;
use Phue\Modules\Queue\Exception\QueuePayloadException;
use Phue\Modules\Queue\Job;
use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;
use Phue\Modules\Queue\QueueConfig;
use Phue\Modules\Queue\QueueManager;
use Phue\Tests\Queue\Stubs\AttemptRecordingJob;
use Phue\Tests\Queue\Stubs\AttributedJob;
use Phue\Tests\Queue\Stubs\BagJob;
use Phue\Tests\Queue\Stubs\BadAttributeJob;
use Phue\Tests\Queue\Stubs\CustomCodecJob;
use Phue\Tests\Queue\Stubs\FailingJob;
use Phue\Tests\Queue\Stubs\NoConstructorJob;
use Phue\Tests\Queue\Stubs\NonRetryableJob;
use Phue\Tests\Queue\Stubs\ObjectArgumentJob;
use Phue\Tests\Queue\Stubs\EntityStub;
use Phue\Tests\Queue\Stubs\PlainJob;
use Phue\Tests\Queue\Stubs\ReleasingJob;
use Phue\Tests\Queue\Stubs\StrictAttributeJob;
use Phue\Tests\Queue\Stubs\ThrowingJob;

/**
 * What a job's lifecycle looks like from the outside.
 *
 * The rule this file exists to protect is narrow: the retry decision is made in exactly one place.
 * Every way a job can fail — throwing, refusing, releasing itself, or being misconfigured — has to
 * end up with the same visible outcome, and the way to be sure of that is to assert the outcome
 * rather than the branch that produced it.
 *
 * @package Phue\Tests\Queue
 */
#[Group('unit')]
final class QueueManagerTest extends TestCase
{
    private SyncQueueDriver $driver;

    private QueueConfig $config;

    private QueueManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new SyncQueueDriver();
        $this->config = QueueConfig::fromArray(['backoff_jitter' => false]);
        $this->manager = new QueueManager($this->driver, $this->config);
    }

    // ------------------------------------------------------------------ pushing

    public function testAPushedJobIsStored(): void
    {
        $id = $this->manager->push(new PlainJob('welcome', 1));

        $this->assertNotSame('', $id);
        $this->assertSame(1, $this->driver->stats()['total']);
    }

    /**
     * The promise of the default driver: pushing a job runs it, so a failure shows up while the
     * developer is still looking at the code that caused it.
     */
    public function testTheDefaultDriverRunsTheJobBeforePushReturns(): void
    {
        PlainJob::$ran = [];

        $this->manager->push(new PlainJob('inline', 5));

        $this->assertSame(['inline:5@1'], PlainJob::$ran);
    }

    /**
     * The job that runs is rebuilt from its payload, not the object the caller passed in. The
     * inline driver could have shortcut that and run the original, but then a job that only works
     * on the instance in hand would pass in development and fail in a worker — which is the whole
     * failure mode that choosing an inline default was meant to rule out.
     */
    public function testTheObjectThatRunsIsAReplicaNotTheOnePushed(): void
    {
        PlainJob::$ran = [];
        $job = new PlainJob('inline', 5);

        $this->manager->push($job);

        $this->assertSame(0, $job->handledOn, 'the pushed instance must not be the one that runs');
        $this->assertSame(['inline:5@1'], PlainJob::$ran, 'a replica of it ran instead');
    }

    public function testAJobCanNameItsOwnQueue(): void
    {
        $this->manager->push(new PlainJob(), 'reports');

        $this->assertSame(1, $this->driver->stats('reports')['completed']);
        $this->assertSame(0, $this->driver->stats('default')['total']);
    }

    public function testAnIllegalQueueNameIsRefusedAtPush(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not a legal queue name/');

        $this->manager->push(new PlainJob(), 'team/emails');
    }

    public function testANegativeDelayIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot be negative/');

        $this->manager->push(new PlainJob(), null, -1);
    }

    /**
     * Running it now instead would be a job that did the exact opposite of what it was asked to
     * do, without saying so.
     */
    public function testADelayTheDriverCannotHonourIsRefusedRatherThanIgnored(): void
    {
        try {
            $this->manager->later(new PlainJob(), 60);
            $this->fail('Expected the delay to be refused.');
        } catch (QueuePayloadException $e) {
            $this->assertStringContainsString('has nowhere to keep a job until then', $e->getMessage());
            $this->assertStringContainsString('queue.driver', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ refusals at push time

    public function testAJobHoldingAnObjectIsRefusedAtPush(): void
    {
        $this->expectException(QueuePayloadException::class);
        $this->expectExceptionMessageMatches('/parameter \$user/');

        $this->manager->push(new ObjectArgumentJob(new EntityStub(1)));
    }

    /**
     * Refused before it is stored, not after. A payload that cannot be carried would otherwise sit
     * in the queue until a worker met it.
     */
    public function testAJobThatCannotBeEncodedIsNeverStored(): void
    {
        try {
            $this->manager->push(new ObjectArgumentJob(new EntityStub(1)));
        } catch (QueuePayloadException) {
            // Expected.
        }

        $this->assertSame(0, $this->driver->stats()['total']);
    }

    public function testAnOversizedPayloadIsRefused(): void
    {
        $manager = new QueueManager(
            new SyncQueueDriver(),
            QueueConfig::fromArray(['max_payload_bytes' => 1024])
        );

        $this->expectException(QueuePayloadException::class);
        $this->expectExceptionMessageMatches('/is \d+ bytes, over the 1024 byte limit/');

        $manager->push(new PlainJob(str_repeat('a', 2000)));
    }

    public function testAClassOutsideTheAllowedNamespacesIsRefused(): void
    {
        $manager = new QueueManager(
            new SyncQueueDriver(),
            QueueConfig::fromArray(['job_namespaces' => ['App\\Jobs']])
        );

        try {
            $manager->push(new PlainJob());
            $this->fail('Expected the namespace allowlist to refuse the job.');
        } catch (QueuePayloadException $e) {
            $this->assertStringContainsString('not inside any configured queue.job_namespaces', $e->getMessage());
            $this->assertStringContainsString('App\\Jobs', $e->getMessage());
        }
    }

    public function testAClassInsideTheAllowedNamespacesIsAccepted(): void
    {
        $manager = new QueueManager(
            new SyncQueueDriver(),
            QueueConfig::fromArray(['job_namespaces' => ['Phue\\Tests\\Queue\\Stubs']])
        );

        $manager->push(new PlainJob('allowed'));

        $this->assertSame(1, $manager->stats()['completed']);
    }

    /**
     * A stored payload names the class a worker will construct. Anyone who can write a row can
     * therefore choose the class, which is a far bigger capability than "can enqueue a job".
     */
    public function testTheAllowlistIsAlsoEnforcedWhenRehydrating(): void
    {
        $manager = new QueueManager(
            new SyncQueueDriver(),
            QueueConfig::fromArray(['job_namespaces' => ['App\\Jobs']])
        );

        $this->expectException(QueuePayloadException::class);
        $this->expectExceptionMessageMatches('/not inside any configured/');

        $manager->hydrate($this->envelopeFor(PlainJob::class));
    }

    // ------------------------------------------------------------------ failure paths

    public function testAThrowingJobIsDeadLetteredAndTheReasonIsRecorded(): void
    {
        $this->manager->push(new ThrowingJob('disk is full'));

        $letters = $this->driver->deadLetters();

        $this->assertCount(1, $letters);
        $this->assertSame('disk is full', $letters[0]['error']);
        $this->assertSame(ThrowingJob::class, $letters[0]['jobClass']);
    }

    public function testAFailedJobLeavesNothingPending(): void
    {
        $this->manager->push(new ThrowingJob());

        $this->assertSame(0, $this->driver->stats()['pending']);
        $this->assertSame(1, $this->driver->stats()['failed']);
    }

    public function testAJobThatGivesUpOnItselfIsNotRetried(): void
    {
        $this->manager->push(new FailingJob());

        $this->assertCount(1, $this->driver->deadLetters());
        $this->assertStringContainsString('never going to be valid', $this->driver->deadLetters()[0]['error']);
    }

    public function testAFailureMarkedNonRetryableIsNotRetried(): void
    {
        $this->manager->push(new NonRetryableJob());

        $this->assertCount(1, $this->driver->deadLetters());
        $this->assertStringContainsString('never succeed', $this->driver->deadLetters()[0]['error']);
    }

    public function testAJobThatReleasesItselfIsNotDeadLettered(): void
    {
        $this->manager->push(new ReleasingJob(30));

        $this->assertSame([], $this->driver->deadLetters());
    }

    /**
     * A job released by an inline driver has no worker to pick it up — the push that created it
     * has already returned — so retrying would leave a permanently pending job with nothing ever
     * running it. Failing it puts the reason where a developer will see it instead.
     */
    public function testAnInlineDriverNeverRetries(): void
    {
        $this->assertSame(\Phue\Modules\Queue\ExecutionModel::Inline, $this->driver->executionModel());

        $this->manager->push(new ThrowingJob('will fail twice if retried'));

        $this->assertCount(1, $this->driver->deadLetters());
        $this->assertSame(0, $this->driver->stats()['pending']);
    }

    // ------------------------------------------------------------------ a deferred driver

    /**
     * Retries are only testable on a driver that defers, because that is the only case where a
     * retry can exist at all. The fake below changes one capability and nothing else, so the
     * behaviour under test is the manager's and not the fake's.
     */
    public function testAFailedJobIsReleasedForAnotherAttemptOnADeferredDriver(): void
    {
        $driver = new DeferredSyncQueueDriver();
        $manager = new QueueManager($driver, QueueConfig::fromArray([
            'backoff_jitter' => false,
            'backoff_base' => 7,
        ]));

        $manager->push(new ThrowingJob());
        $this->assertSame(1, $driver->stats()['pending']);
        $this->assertSame([], $driver->deadLetters());

        $job = $driver->nextPending();
        $this->assertNotNull($job);
        $manager->run($job, 'worker-1');

        $this->assertSame(1, $driver->stats()['pending'], 'released, not dead-lettered');
        $this->assertSame([], $driver->deadLetters());
    }

    public function testAJobIsDeadLetteredOnceItsAttemptsAreExhausted(): void
    {
        $driver = new DeferredSyncQueueDriver();
        $manager = new QueueManager($driver, QueueConfig::fromArray([
            'backoff_jitter' => false,
            'backoff_base' => 0,
            'max_tries' => 3,
        ]));

        $manager->push(new ThrowingJob());

        $attempts = [];

        for ($run = 1; $run <= 3; $run++) {
            $job = $driver->nextPending();
            $this->assertNotNull($job, 'attempt ' . $run . ' should have been available');
            $attempts[] = $job->attempts;
            $manager->run($job, 'worker-1');
        }

        $this->assertSame([1, 2, 3], $attempts);
        $this->assertCount(1, $driver->deadLetters());
        $this->assertSame(0, $driver->stats()['pending']);
    }

    public function testAJobStopsAfterExactlyTheConfiguredNumberOfAttempts(): void
    {
        $driver = new DeferredSyncQueueDriver();
        $manager = new QueueManager($driver, QueueConfig::fromArray([
            'backoff_jitter' => false,
            'backoff_base' => 0,
            'max_tries' => 2,
        ]));

        $manager->push(new ThrowingJob());

        // Two attempts are configured, so there are two runs and then nothing.
        $this->assertTrue($manager->runNext());
        $this->assertTrue($manager->runNext());
        $this->assertFalse($manager->runNext(), 'a third attempt must not exist');

        $this->assertNull($driver->nextPending());
        $this->assertCount(1, $driver->deadLetters());
    }

    public function testAnExplicitReleaseIsHonouredInsteadOfTheBackoffCurve(): void
    {
        $driver = new DeferredSyncQueueDriver();
        $manager = new QueueManager($driver, QueueConfig::fromArray([
            'backoff_jitter' => false,
            'backoff_base' => 600,
        ]));

        $manager->push(new ReleasingJob(30));
        $job = $driver->nextPending();
        $this->assertNotNull($job);
        $manager->run($job, 'worker-1');

        $requeued = $driver->nextPending();
        $this->assertNotNull($requeued);
        $this->assertLessThan(600, $requeued->availableAt - $job->availableAt);
    }

    // ------------------------------------------------------------------ per-job overrides

    public function testTheJobsOwnAttributeOverridesTheConfiguredQueue(): void
    {
        $this->manager->push(new AttributedJob());

        $this->assertSame(1, $this->driver->stats('emails')['completed']);
        $this->assertSame(0, $this->driver->stats('default')['total']);
    }

    public function testTheJobsOwnAttributeOverridesTheConfiguredAttemptLimit(): void
    {
        $driver = new DeferredSyncQueueDriver();
        $manager = new QueueManager($driver, QueueConfig::fromArray([
            'backoff_jitter' => false,
            'backoff_base' => 0,
            'max_tries' => 5,
        ]));

        // StrictAttributeJob declares tries: 1, so its very first failure is terminal even though
        // the configuration would have allowed four more.
        $manager->push(new StrictAttributeJob());

        $this->assertTrue($manager->runNext());
        $this->assertFalse($manager->runNext());
        $this->assertCount(1, $driver->deadLetters());
    }

    public function testACallerSuppliedQueueBeatsTheAttribute(): void
    {
        $this->manager->push(new AttributedJob(), 'override');

        $this->assertSame(1, $this->driver->stats('override')['completed']);
        $this->assertSame(0, $this->driver->stats('emails')['total']);
    }

    /**
     * A job cannot make its own configuration valid. It has to run, and quietly falling back to
     * the configured values is better than refusing the job or letting tries: 0 mean something
     * absurd.
     */
    public function testAnAttributeThatCannotBeHonouredFallsBackToTheConfiguration(): void
    {
        $manager = new QueueManager(
            new DeferredSyncQueueDriver(),
            QueueConfig::fromArray(['backoff_jitter' => false, 'backoff_base' => 0, 'max_tries' => 3])
        );

        $manager->push(new BadAttributeJob());
        $job = $manager->reserve('default');

        $this->assertNotNull($job, 'the configured queue should have been used');
        $this->assertSame(3, JobContext::forAttempt($job, 3, 60, 'w')->maxTries);
    }

    // ------------------------------------------------------------------ the job, on its own

    /**
     * The cheapest test a job can have, and the reason the job is a plain object with a promoted
     * property: no container, no queue, no driver. Everything else in this file is testing the
     * machinery around the job, and this is testing the job.
     */
    public function testAJobCanBeRunWithoutTheQueueAtAll(): void
    {
        PlainJob::$ran = [];

        (new PlainJob('standalone', 2))->handle(JobContext::standalone(PlainJob::class));

        $this->assertSame(['standalone:2@1'], PlainJob::$ran);
    }

    public function testACustomEncodedJobRoundTripsThroughTheManager(): void
    {
        $this->manager->push(CustomCodecJob::make('x-1'));

        $this->assertSame(1, $this->driver->stats()['completed']);
    }

    public function testAJobWithNoStateIsAccepted(): void
    {
        $this->manager->push(new NoConstructorJob());

        $this->assertSame(1, $this->driver->stats()['completed']);
    }

    public function testAReflectionRoundTripThroughTheManagerPreservesTheValues(): void
    {
        $manager = new QueueManager(new DeferredSyncQueueDriver(), $this->config);
        $manager->push(new PlainJob('carried: value', 42));

        $envelope = $manager->reserve('default');
        $this->assertNotNull($envelope);

        $job = $manager->hydrate($envelope);

        $this->assertInstanceOf(PlainJob::class, $job);
        $this->assertSame('carried: value', $job->subject);
        $this->assertSame(42, $job->count);
    }

    // ------------------------------------------------------------------ the context

    public function testTheContextKnowsWhichAttemptItIs(): void
    {
        $driver = new DeferredSyncQueueDriver();
        $manager = new QueueManager($driver, QueueConfig::fromArray(['backoff_jitter' => false]));
        $manager->push(new AttemptRecordingJob());
        $manager->runNext();

        $this->assertSame([1], AttemptRecordingJob::$seen);
    }

    public function testTheContextKnowsWhenTheNextFailureIsTheLastOne(): void
    {
        $manager = new QueueManager(new DeferredSyncQueueDriver(), QueueConfig::fromArray([
            'backoff_jitter' => false,
            'max_tries' => 2,
        ]));

        $context = JobContext::forAttempt($this->envelopeFor(PlainJob::class), 2, 60, 'w');
        $this->assertFalse($context->isFinalAttempt());

        $final = JobContext::forAttempt($this->envelopeFor(PlainJob::class, attempts: 2), 2, 60, 'w');
        $this->assertTrue($final->isFinalAttempt());
    }

    public function testReleasingFromAStandaloneTestIsRefused(): void
    {
        $this->expectException(QueuePayloadException::class);
        $this->expectExceptionMessageMatches('/only mean something while a worker is running/');

        JobContext::standalone(PlainJob::class)->release();
    }

    public function testTheAttemptBagCarriesValuesBetweenAttempts(): void
    {
        $driver = new DeferredSyncQueueDriver();
        $manager = new QueueManager($driver, QueueConfig::fromArray(['backoff_jitter' => false, 'backoff_base' => 0]));
        $manager->push(new BagJob());
        $manager->runNext();

        $this->assertSame(['seen' => 1], BagJob::$bag);
    }

    // ------------------------------------------------------------------ plumbing

    public function testTheWorkerIdIsUniquePerClaimWithinAProcess(): void
    {
        $first = $this->manager->workerId();
        $second = $this->manager->workerId();

        $this->assertNotSame($first, $second);
    }

    public function testAnEmptyQueueRunsNothing(): void
    {
        $this->assertFalse($this->manager->runNext());
    }

    public function testStatsAreForwardedToTheDriver(): void
    {
        $this->manager->push(new PlainJob());

        $this->assertSame($this->driver->stats(), $this->manager->stats());
    }

    public function testPruningIsForwardedToTheDriver(): void
    {
        $this->manager->push(new PlainJob());

        $this->assertSame(1, $this->manager->prune(0));
    }

    private function envelopeFor(string $jobClass, int $attempts = 1): \Phue\Modules\Queue\JobEnvelope
    {
        return new \Phue\Modules\Queue\JobEnvelope(
            id: '1',
            queue: 'default',
            jobClass: $jobClass,
            payload: [],
            status: \Phue\Modules\Queue\JobStatus::Reserved,
            attempts: $attempts,
            availableAt: 0,
            reservedBy: 'worker-1',
        );
    }
}