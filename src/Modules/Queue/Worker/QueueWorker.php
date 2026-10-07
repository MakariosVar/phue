<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Worker;

use Phue\Core\Container;
use Phue\Core\Database\Connection;
use Phue\Modules\Queue\JobOutcome;
use Phue\Modules\Queue\QueueManager;

/**
 * Runs jobs until it is told to stop, the queue is empty, or it runs out of something.
 *
 * The loop is the easy part. What makes a worker correct is what it does around each job, and
 * there is exactly one thing it must do that is easy to miss:
 *
 * Per job, in this order, always:
 *   1. take a fresh container scope, so nothing the previous job bound can be seen by this one;
 *   2. roll back any transaction the previous job left open;
 *   3. run it;
 *   4. in a finally, roll back again and flush the scope.
 *
 * A worker runs many jobs in one process, and Application::handle() — which does exactly this —
 * is never entered from the console. So nothing else does it. A worker without it does not fail
 * loudly; it lets job number two inherit whatever job number one left behind, which is how a queue
 * ends up processing a customer's data with the previous customer's request still in the container.
 *
 * On top of that: a lease reaper tick, so a job held by a worker that will never come back becomes
 * visible in the counts as well as claimable; and three ways out that are all deliberate, because a
 * process killed by the memory limit mid-job leaves work to be discovered later.
 *
 * @package Phue\Modules\Queue\Worker
 */
final class QueueWorker
{
    /**
     * How often the expired-lease sweep runs, in iterations.
     *
     * Not every iteration: the sweep is a query against the live table, and doing it per job would
     * double the work on an idle worker. Not never: an operator looking at the counts should not
     * have to know that a queue with one abandoned job and no other traffic still needs a worker.
     */
    public const DEFAULT_REAPER_INTERVAL = 25;

    /**
     * Fraction of its starting headroom the worker may use before leaving.
     *
     * Below the whole of it because the allocation that trips the limit would take the process
     * down mid-job, and a clean exit at 80% is recoverable where a kill is not.
     */
    public const MEMORY_CEILING = 0.8;

    /**
     * @param Container              $container Supplies per-job scopes; injected so the worker can
     *                                         be tested without an application.
     * @param Connection|null         $connection Rolled back between jobs when it implements
     *                                         ResettableInterface, so an aborted job cannot leave
     *                                         a transaction holding locks.
     * @param StopSignalInterface     $signals    Asks the worker to stop.
     * @param int                     $sleepMicroseconds Wait between empty claims.
     * @param int                     $reaperEvery How often to sweep expired leases.
     */
    public function __construct(
        private readonly QueueManager $manager,
        private readonly Container $container,
        private readonly ?Connection $connection = null,
        private readonly ?StopSignalInterface $signals = null,
        private readonly int $sleepMicroseconds = 250_000,
        private readonly int $reaperEvery = self::DEFAULT_REAPER_INTERVAL,
    ) {
    }

    /**
     * Work the queue until one of the limits is reached.
     *
     * An empty queue ends the run when $stopWhenEmpty is true, which is the cron-invoked case: a
     * worker woken every minute should find nothing, report nothing, and exit. When it is false the
     * worker waits and looks again, which is the supervised case. Both are useful and they are
     * opposite behaviours, so the caller says which one it wants rather than it being inferred
     * from the absence of other arguments.
     *
     * @param string|null $queue         One queue, or null for every queue the driver serves.
     * @param int|null    $maxJobs       Stop after this many jobs. Null runs until something else
     *                                    stops it, which only makes sense with a signal or a time
     *                                    limit — a worker with neither will not return.
     * @param float|null  $maxSeconds    Stop after this long. A float because it is compared
     *                                   against a clock, and a whole-second granularity cannot
     *                                   express "until the next check".
     * @param bool        $stopWhenEmpty Treat an empty queue as the end of the run.
     *
     * @return WorkReport What was done, and which limit ended the run.
     */
    public function run(
        ?string $queue = null,
        ?int $maxJobs = null,
        ?float $maxSeconds = null,
        bool $stopWhenEmpty = true
    ): WorkReport {
        $startedAt = microtime(true);
        $deadline = $maxSeconds === null ? null : $startedAt + $maxSeconds;

        // Recorded here rather than in the constructor, because a worker built once and run many
        // times has to be measured from the start of each run.
        $this->baselineMemoryBytes = memory_get_usage(true);

        $workerId = $this->manager->workerId();
        $signals = $this->signals;

        $run = 0;
        $succeeded = 0;
        $failed = 0;
        $reclaimed = 0;
        $iterations = 0;

        while (true) {
            if ($signals?->stopRequested() === true) {
                return WorkReport::signalled($run, $succeeded, $failed, $reclaimed, $iterations, $this->elapsed($startedAt));
            }

            if ($maxJobs !== null && $run >= $maxJobs) {
                return WorkReport::jobLimit($run, $succeeded, $failed, $reclaimed, $iterations, $this->elapsed($startedAt));
            }

            if ($deadline !== null && microtime(true) >= $deadline) {
                return WorkReport::timeLimit($run, $succeeded, $failed, $reclaimed, $iterations, $this->elapsed($startedAt));
            }

            if ($this->memoryCeilingReached()) {
                return WorkReport::outOfMemory($run, $succeeded, $failed, $reclaimed, $iterations, $this->elapsed($startedAt));
            }

            $iterations++;

            $outcome = $this->runOne($queue, $workerId);

            if ($outcome === null) {
                if ($stopWhenEmpty) {
                    return WorkReport::drained(
                        $run,
                        $succeeded,
                        $failed,
                        $reclaimed,
                        $iterations,
                        $this->elapsed($startedAt)
                    );
                }

                // Nothing to do. Sleeping rather than spinning matters here: an empty queue with
                // no sleep is a loop that does thousands of claims a second and shows up as a
                // database that is busy while doing nothing.
                if ($this->sleepMicroseconds > 0) {
                    usleep($this->sleepMicroseconds);
                }

                continue;
            }

            $run++;

            if ($outcome === JobOutcome::Completed) {
                $succeeded++;
            } elseif ($outcome === JobOutcome::Failed) {
                $failed++;
            }

            if ($this->reaperEvery > 0 && $iterations % $this->reaperEvery === 0) {
                $reclaimed += $this->manager->reclaimExpiredLeases();
            }
        }
    }

    /**
     * One claim, one job, with the isolation around it.
     *
     * @return JobOutcome|null Null when the queue was empty.
     */
    private function runOne(?string $queue, string $workerId): ?JobOutcome
    {
        $scope = $this->container->createScope();
        $this->container->setScope($scope);

        try {
            return $this->manager->processNext($queue, $workerId);
        } finally {
            // Order matters. The transaction is rolled back first: flushing the scope resets the
            // services bound in it, and a Connection that is reset by the container is not given
            // this explicit pass. A job that died holding an open transaction would otherwise keep
            // its locks and its row until the connection eventually timed out.
            $this->connection?->reset();
            $this->container->flushScope($scope);
            $this->container->setScope(null);
        }
    }

    /**
     * Whether the worker has used up the room it started with.
     *
     * Measured against the memory in use when the run began, not against the absolute limit,
     * because the two answer different questions. A process that was already using 110MB when the
     * worker started has not filled anything up, and a worker that stopped because of it would be
     * attributing somebody else's memory to the queue — and would stop doing its job in exactly
     * the process that had the most to do. What matters is how much the worker itself grew.
     *
     * The fraction applies to the headroom the worker began with, so it leaves before the next
     * allocation is the one that cannot be served. A limit of -1 means unlimited, which is not a
     * fraction of anything, and is reported as never reached.
     */
    public function memoryCeilingReached(): bool
    {
        $limit = $this->memoryLimitBytes();

        if ($limit === null) {
            return false;
        }

        // Before a run has started there is nothing to charge to the worker, so the baseline is
        // wherever the process happens to be. Answering any other question here would report a
        // ceiling on the caller's own memory as though it were the queue's.
        $baseline = $this->baselineMemoryBytes > 0 ? $this->baselineMemoryBytes : memory_get_usage(true);
        $headroom = max(0, $limit - $baseline);

        return memory_get_usage(true) >= $baseline + (int) ($headroom * self::MEMORY_CEILING);
    }

    /**
     * The memory the process had already used when the run began, recorded once so every check in
     * the loop measures against the same starting point. Zero until the first run.
     */
    private int $baselineMemoryBytes = 0;

    /**
     * The configured memory limit in bytes, or null when there is none.
     */
    private function memoryLimitBytes(): ?int
    {
        $configured = ini_get('memory_limit');

        if (!is_string($configured) || trim($configured) === '' || $configured === '-1') {
            return null;
        }

        $value = trim($configured);
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return (int) match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Seconds since the run started.
     *
     * @param float $startedAt The microtime(true) the run began at.
     */
    private function elapsed(float $startedAt): float
    {
        return microtime(true) - $startedAt;
    }
}