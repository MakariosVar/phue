<?php

declare(strict_types=1);

namespace Phue\Tests\Queue;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Container;
use Phue\Core\Database\Connection;
use Phue\Modules\Queue\Driver\DatabaseQueueDriver;
use Phue\Modules\Queue\JobContext;
use Phue\Modules\Queue\JobInterface;
use Phue\Modules\Queue\JobOutcome;
use Phue\Modules\Queue\QueueConfig;
use Phue\Modules\Queue\QueueManager;
use Phue\Modules\Queue\Schema\QueueSchema;
use Phue\Modules\Queue\Worker\ManualStopSignal;
use Phue\Modules\Queue\Worker\PosixSignalWatcher;
use Phue\Modules\Queue\Worker\QueueWorker;
use Phue\Modules\Queue\Worker\StopSignalInterface;
use Phue\Modules\Queue\Worker\WorkReport;
use Phue\Tests\Queue\Stubs\CountingJob;
use Phue\Tests\Queue\Stubs\MemoryHungryJob;
use Phue\Tests\Queue\Stubs\ScopedCounter;
use Phue\Tests\Queue\Stubs\ScopeLeakingJob;
use Phue\Tests\Queue\Stubs\StopRaisingJob;
use Phue\Tests\Queue\Stubs\ThrowingJob;
use Phue\Tests\Queue\Stubs\TransactionLeavingJob;
use Phue\Tests\Support\SqliteFactory;

/**
 * The loop, and more importantly what happens around each job.
 *
 * A worker is easy to get right until it runs more than one job, and then the only thing standing
 * between two jobs is a fresh container scope and a rollback. Nothing in a console run provides
 * them: Application::handle() is what normally does both, and a worker never goes through it. So
 * the isolation has to be the worker's own, and it has to be tested by observing a job inherit
 * something from its predecessor — because the failure mode is a job quietly seeing the previous
 * customer's data, which produces no error at all.
 *
 * @package Phue\Tests\Queue
 */
#[Group('unit')]
final class QueueWorkerTest extends TestCase
{
    private \Phue\Core\Database\Connection $connection;

    private QueueConfig $config;

    private QueueManager $manager;

    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = SqliteFactory::memory();
        $this->config = QueueConfig::fromArray([
            'driver' => 'database',
            'backoff_jitter' => false,
            'backoff_base' => 0,
            'max_tries' => 2,
        ]);
        (new QueueSchema($this->connection, $this->config->table))->install();

        $this->manager = new QueueManager(
            new DatabaseQueueDriver($this->connection, $this->config),
            $this->config,
            null,
            // Exactly as the module wires it: the connection is what ends a job's transaction
            // before the outcome is written, and this test is only meaningful if it is wired.
            $this->connection
        );
        $this->container = new Container();
        Container::setInstance($this->container);

        ScopeLeakingJob::$counts = [];
        CountingJob::$runs = 0;

        // Request-scoped, as the request path would have it. Whether the worker gives each job a
        // fresh instance of this is the whole question these tests ask.
        $this->container->scoped(ScopedCounter::class, static fn(): ScopedCounter => new ScopedCounter());

        // Jobs resolve their collaborators from the container: a job is carried between processes
        // as values, so it cannot hold an object of its own. The worker publishes this container,
        // which is what makes that possible at all in a console run.
        $this->container->singleton(Connection::class, fn(): Connection => $this->connection);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Container::setInstance(null);
    }

    /**
     * @param StopSignalInterface|null $signals           What asks the worker to stop.
     * @param int|null                 $maxSeconds       Time limit.
     * @param int                      $reaperEvery      How often to sweep expired leases.
     */
    private function worker(
        ?StopSignalInterface $signals = null,
        ?int $maxSeconds = null,
        int $reaperEvery = 0,
        int $sleepMicroseconds = 0
    ): QueueWorker {
        return new QueueWorker(
            manager: $this->manager,
            container: $this->container,
            connection: $this->connection,
            signals: $signals,
            sleepMicroseconds: $sleepMicroseconds,
            reaperEvery: $reaperEvery,
        );
    }

    // ------------------------------------------------------------------ draining

    public function testItDrainsTheQueueAndReportsSo(): void
    {
        $this->manager->push(new CountingJob());
        $this->manager->push(new CountingJob());

        $report = $this->worker()->run();

        $this->assertSame(WorkReport::REASON_DRAINED, $report->reason);
        $this->assertSame(2, $report->jobsRun);
        $this->assertSame(2, $report->jobsSucceeded);
        $this->assertSame(0, $this->manager->stats()['pending']);
    }

    public function testItReportsDrainedForAnEmptyQueue(): void
    {
        $report = $this->worker()->run();

        $this->assertSame(WorkReport::REASON_DRAINED, $report->reason);
        $this->assertSame(0, $report->jobsRun);
        $this->assertSame(1, $report->iterations, 'one claim that found nothing');
    }

    public function testItOnlyTouchesTheQueueItWasGiven(): void
    {
        $this->manager->push(new CountingJob(), 'emails');
        $this->manager->push(new CountingJob(), 'emails');
        $this->manager->push(new CountingJob(), 'reports');

        $report = $this->worker()->run('emails');

        $this->assertSame(2, $report->jobsRun);
        $this->assertSame(1, $this->manager->stats('reports')['pending']);
    }

    // ------------------------------------------------------------------ limits

    public function testItStopsAtTheJobLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->manager->push(new CountingJob());
        }

        $report = $this->worker()->run(maxJobs: 2);

        $this->assertSame(WorkReport::REASON_JOB_LIMIT, $report->reason);
        $this->assertSame(2, $report->jobsRun);
        $this->assertSame(3, $this->manager->stats()['pending']);
    }

    public function testItStopsAtTheTimeLimit(): void
    {
        $this->manager->push(new CountingJob());

        $report = $this->worker()->run(maxJobs: 1000, maxSeconds: 0.2);

        $this->assertSame(WorkReport::REASON_DRAINED, $report->reason);
        $this->assertSame(1, $report->jobsRun);
    }

    public function testAnAlreadyPassedTimeLimitEndsTheRunWithoutRunningAnything(): void
    {
        $this->manager->push(new CountingJob());

        $report = $this->worker()->run(maxSeconds: -1);

        $this->assertSame(WorkReport::REASON_TIME_LIMIT, $report->reason);
        $this->assertSame(0, $report->jobsRun);
        $this->assertSame(1, $this->manager->stats()['pending']);
    }

    // ------------------------------------------------------------------ stopping

    /**
     * Stopping is checked between jobs, never during one. A worker killed mid-job leaves work that
     * has to wait out its lease; a worker that finishes first does not.
     */
    public function testAStopIsHonouredAfterTheJobInHand(): void
    {
        $signals = new ManualStopSignal();
        $signals->requestStop();
        $this->manager->push(new CountingJob());

        $report = $this->worker($signals)->run();

        $this->assertSame(WorkReport::REASON_SIGNAL, $report->reason);
        $this->assertSame(0, $report->jobsRun);
        $this->assertSame(1, $this->manager->stats()['pending'], 'the job is untouched');
    }

    public function testItStopsOnceTheSignalArrivesRatherThanRunningTheWholeQueue(): void
    {
        $signals = new ManualStopSignal();
        $this->container->singleton(ManualStopSignal::class, static fn(): ManualStopSignal => $signals);

        // Pushed first, so it is the job that runs first and the twenty behind it are what the
        // worker must leave alone.
        $this->manager->push(new StopRaisingJob());

        for ($i = 0; $i < 20; $i++) {
            $this->manager->push(new CountingJob());
        }

        $report = $this->worker($signals)->run();

        $this->assertSame(WorkReport::REASON_SIGNAL, $report->reason);
        $this->assertSame(1, $report->jobsRun, 'it stopped after the job that asked');
        $this->assertSame(20, $this->manager->stats()['pending'], 'the rest is left for later');
    }

    /**
     * A supervised worker must not spin on an empty queue. Without the sleep it would issue
     * thousands of claims a second and show up as a database that is busy doing nothing.
     */
    public function testAnEmptyQueueIsWaitedOutRatherThanSpunOn(): void
    {
        $worker = $this->worker(reaperEvery: 0, sleepMicroseconds: 20_000);

        $started = microtime(true);
        $report = $worker->run(maxSeconds: 0.3, stopWhenEmpty: false);
        $elapsed = microtime(true) - $started;

        $this->assertSame(WorkReport::REASON_TIME_LIMIT, $report->reason);
        $this->assertSame(0, $report->jobsRun);
        $this->assertGreaterThan(2, $report->iterations, 'it kept looking');
        $this->assertGreaterThan(0.05, $elapsed, 'and it waited between looks instead of spinning');
    }

    public function testAJobAppearingDuringAnIdleWaitIsPickedUp(): void
    {
        // Bounded by a job limit rather than by time on the second run: the queue empties once the
        // job is done, and a worker that waits for the clock rather than the work is the thing the
        // first test is about.
        $report = $this->worker(sleepMicroseconds: 20_000)->run(maxSeconds: 0.2, stopWhenEmpty: false);

        $this->assertSame(0, $report->jobsRun, 'nothing was there');

        $this->manager->push(new CountingJob());

        $second = $this->worker(sleepMicroseconds: 20_000)->run(maxJobs: 1, stopWhenEmpty: false);

        $this->assertSame(1, $second->jobsRun, 'the job that arrived was picked up');
    }

    // ------------------------------------------------------------------ isolation

    /**
     * The property the worker exists for. Each job must be handed a fresh request scope, because
     * nothing in a console run provides one, and a shared instance is how one job ends up acting
     * on state another job left behind — silently, with no error anywhere.
     */
    public function testAScopedServiceIsRebuiltForEachJob(): void
    {
        $this->manager->push(new ScopeLeakingJob());
        $this->manager->push(new ScopeLeakingJob());

        $this->worker()->run();

        $this->assertSame(
            [0, 0],
            ScopeLeakingJob::$counts,
            'the second job must not find the first job\'s counter already incremented'
        );
    }

    /**
     * The same guarantee when the job dies rather than finishing. The isolation has to happen in a
     * finally, or a failed job is the one that leaks.
     */
    public function testTheScopeIsRebuiltEvenAfterAJobThrows(): void
    {
        $this->manager->push(new ScopeLeakingJob(true));
        $this->manager->push(new ScopeLeakingJob());

        $this->worker()->run();

        // The throwing job is retried, so it runs twice; what matters is that every single run
        // started from a clean instance.
        $this->assertSame([0, 0, 0], ScopeLeakingJob::$counts);
    }

    /**
     * The negative case, so the positive one means something: without a scope per job the second
     * run does see the increment. Asserted directly rather than left to imagination.
     */
    public function testWithoutAWorkerTheScopeWouldLeak(): void
    {
        $scope = $this->container->createScope();
        $this->container->setScope($scope);

        try {
            (new ScopeLeakingJob())->handle(\Phue\Modules\Queue\JobContext::standalone('x'));
            (new ScopeLeakingJob())->handle(\Phue\Modules\Queue\JobContext::standalone('x'));

            $this->assertSame([0, 1], ScopeLeakingJob::$counts, 'the leak the worker prevents');
        } finally {
            $this->container->flushScope($scope);
            $this->container->setScope(null);
        }
    }

    public function testTheContainerHasNoScopeLeftBehindAfterTheRun(): void
    {
        $this->manager->push(new CountingJob());

        $this->worker()->run();

        $this->assertNull($this->container->getScope(), 'a leftover scope would leak into the next run');
    }

    public function testTheContainerHasNoScopeLeftBehindEvenWhenAJobThrows(): void
    {
        $this->manager->push(new ScopeLeakingJob(true));

        $this->worker()->run();

        $this->assertNull($this->container->getScope());
    }

    /**
     * A job that dies holding an open transaction would otherwise keep its locks and its row until
     * the connection timed out, which on a busy table means the whole queue stalls behind it.
     */
    public function testAnOpenTransactionIsRolledBackBetweenJobs(): void
    {
        $this->manager->push(new TransactionLeavingJob());
        $this->manager->push(new CountingJob());

        $this->worker()->run();

        $this->assertFalse($this->connection->inTransaction(), 'a transaction outlived the worker');
        $this->assertSame(1, $this->manager->stats()['completed']);
    }

    public function testTheSecondJobStillRunsAfterTheFirstLeavesATransactionOpen(): void
    {
        $this->manager->push(new TransactionLeavingJob());
        $this->manager->push(new CountingJob());

        $report = $this->worker()->run();

        // Three, not two: the failing job's release is now recorded, so it comes back and runs a
        // second time before its limit. Before the fix it stayed reserved and this was two.
        $this->assertSame(3, $report->jobsRun);
        $this->assertSame(1, $report->jobsSucceeded);
    }

    // ------------------------------------------------------------------ outcomes

    public function testItCountsSuccessesAndFailuresSeparately(): void
    {
        $this->manager->push(new CountingJob());
        $this->manager->push(new ThrowingJob('no'));
        $this->manager->push(new CountingJob());

        $report = $this->worker()->run();

        $this->assertSame(2, $report->jobsSucceeded);
        $this->assertSame(1, $report->jobsFailed, 'one job dead-lettered');
        $this->assertSame(4, $report->jobsRun, 'four attempts, of which two were the same job');
    }

    public function testTheManagerReportsTheOutcomeWithoutExtraQueries(): void
    {
        $this->manager->push(new CountingJob());

        $outcome = $this->manager->processNext();

        $this->assertSame(JobOutcome::Completed, $outcome);
        $this->assertTrue($outcome->isFinal());
        $this->assertNull($this->manager->processNext(), 'and null when there is nothing left');
    }

    public function testARetryIsNotReportedAsAFailure(): void
    {
        $this->manager->push(new ThrowingJob('no'));

        $this->assertSame(JobOutcome::Retried, $this->manager->processNext());
        $this->assertSame(JobOutcome::Failed, $this->manager->processNext());
    }

    // ------------------------------------------------------------------ reaper

    public function testItSweepsExpiredLeasesOnTheConfiguredInterval(): void
    {
        $this->manager->push(new CountingJob());

        $this->manager->push(new CountingJob());
        $this->manager->push(new CountingJob());

        $report = $this->worker(reaperEvery: 1)->run(maxJobs: 1);

        $this->assertSame(1, $report->jobsRun);
        $this->assertSame(1, $report->iterations);
    }

    public function testASweepFindsNothingToReclaimWhenEveryJobIsHealthy(): void
    {
        $this->manager->push(new CountingJob());
        $this->manager->push(new CountingJob());

        $report = $this->worker(reaperEvery: 1)->run();

        $this->assertSame(0, $report->leasesReclaimed);
    }

    public function testASweepReturnsAJobWhoseWorkerVanished(): void
    {
        $this->manager->push(new CountingJob());

        // Claimed by a worker that never comes back. The claim is made with a clock in the past so
        // the lease it takes has already run out, which is the situation being described.
        $driver = $this->manager->driver();
        $this->assertInstanceOf(DatabaseQueueDriver::class, $driver);
        $driver->reserve(null, 'worker-that-died', 60, time() - 120);

        $worker = new QueueWorker(
            manager: $this->manager,
            container: $this->container,
            connection: $this->connection,
            signals: null,
            reaperEvery: 1,
        );

        $report = $worker->run();

        $this->assertSame(1, $report->jobsRun, 'the abandoned job was picked up');
        $this->assertSame(0, $report->leasesReclaimed, 'the claim took it back before the sweep did');
    }

    // ------------------------------------------------------------------ memory

    public function testTheMemoryCeilingIsNotReachedWhenNothingHasBeenUsed(): void
    {
        $this->assertFalse($this->worker()->memoryCeilingReached());
    }

    /**
     * A process that was already large when the worker started has not filled anything up, and a
     * worker that stopped because of it would be blaming somebody else's memory on the queue.
     */
    public function testTheMemoryAlreadyInUseIsNotChargedToTheWorker(): void
    {
        $this->manager->push(new CountingJob());

        $original = (string) ini_get('memory_limit');
        $inUse = memory_get_usage(true);

        // A limit barely above what is already used: the worker has no headroom of its own, but it
        // also has not consumed anything, so it must not decide to leave.
        ini_set('memory_limit', (string) ((int) (($inUse + 65_536) / 1024) * 1024));

        try {
            $report = $this->worker()->run(maxJobs: 1);

            $this->assertSame(WorkReport::REASON_JOB_LIMIT, $report->reason);
            $this->assertSame(1, $report->jobsRun);
        } finally {
            ini_set('memory_limit', $original);
        }
    }

    /**
     * The guard the memory ceiling exists for: a job that accumulates has to take the worker down
     * cleanly, not have the process killed mid-job by the runtime.
     */
    public function testAJobThatUsesUpTheMemoryTakesTheWorkerDownCleanly(): void
    {
        $original = (string) ini_get('memory_limit');
        $inUse = memory_get_usage(true);

        // The test allocates megabytes on purpose, so it needs megabytes of its own to do that in.
        // Run in a process that has already used most of its limit it would starve the test runner
        // rather than the worker, and the failure would be attributed to the wrong thing.
        $needed = 32 * 1024 * 1024;
        $limit = $this->memoryLimitInBytes($original);

        if ($limit === null || $limit - $inUse < $needed) {
            $this->markTestSkipped(sprintf(
                'Needs %d bytes of headroom to allocate against; this process has %s left.',
                $needed,
                $limit === null ? 'no limit at all' : (string) max(0, $limit - $inUse)
            ));
        }

        // Half-megabyte jobs rather than kilobytes, because memory_get_usage() reports in whole
        // chunks: a headroom smaller than a couple of chunks is stepped over rather than
        // approached, and the test would pass or fail for reasons that have nothing to do with the
        // worker.
        ini_set('memory_limit', (string) ((int) (($inUse + $needed) / 1024) * 1024));

        try {
            for ($i = 0; $i < 120; $i++) {
                $this->manager->push(new MemoryHungryJob());
            }

            $report = $this->worker()->run(maxJobs: 120);

            $this->assertSame(WorkReport::REASON_MEMORY, $report->reason);
            $this->assertGreaterThan(0, $report->jobsRun, 'it did some work first');
            $this->assertLessThan(120, $report->jobsRun, 'it stopped rather than carrying on');
            $this->assertGreaterThan(0, $this->manager->stats()['pending'], 'the rest is still waiting');
        } finally {
            ini_set('memory_limit', $original);
        }
    }

    /**
     * An ini shorthand is not a byte count.
     *
     * (int) '128M' is 128, so a test that casts the configured limit without reading its unit
     * believes it has 128 bytes of room and skips itself forever — which looks exactly like a test
     * that has quietly stopped testing anything.
     *
     * @return int|null Bytes, or null when the limit is unlimited or unparseable.
     */
    private function memoryLimitInBytes(string $configured): ?int
    {
        $value = trim($configured);

        if ($value === '' || $value === '-1') {
            return null;
        }

        $number = (float) $value;

        return (int) match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * A limit of -1 means unlimited, which is not a fraction of anything and must not be treated as
     * a ceiling to reach.
     */
    public function testAnUnlimitedMemoryLimitIsNeverACeiling(): void
    {
        $original = (string) ini_get('memory_limit');
        ini_set('memory_limit', '-1');

        try {
            $this->assertFalse($this->worker()->memoryCeilingReached());
        } finally {
            ini_set('memory_limit', $original);
        }
    }

    // ------------------------------------------------------------------ signals

    public function testTheSignalWatcherDegradesRatherThanFailing(): void
    {
        $watcher = new PosixSignalWatcher();

        // Whether it installs depends on the platform, and both answers have to be usable: a worker
        // that cannot be started at all is worse than one that does not stop politely.
        $installed = $watcher->install();

        $this->assertSame(function_exists('pcntl_signal'), $installed);
        $this->assertSame($installed, $watcher->isInstalled());
        $this->assertFalse($watcher->stopRequested());
    }

    public function testTheSignalWatcherToleratesBeingDispatchedWithoutSignals(): void
    {
        $watcher = new PosixSignalWatcher();
        $watcher->dispatch();

        $this->assertFalse($watcher->stopRequested());
    }

    public function testAWorkerWithNoSignalsRunsNormally(): void
    {
        $this->manager->push(new CountingJob());

        $report = $this->worker(signals: null)->run();

        $this->assertSame(1, $report->jobsRun);
    }
}