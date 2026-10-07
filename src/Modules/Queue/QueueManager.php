<?php

declare(strict_types=1);

namespace Phue\Modules\Queue;

use Phue\Core\ResettableInterface;
use Phue\Modules\Queue\Attribute\Job as JobAttribute;
use Phue\Modules\Queue\Driver\DatabaseQueueDriver;
use Phue\Modules\Queue\Exception\JobFailedException;
use Phue\Modules\Queue\Exception\JobReleasedException;
use Phue\Modules\Queue\Exception\NonRetryableException;
use Phue\Modules\Queue\Exception\QueuePayloadException;

/**
 * Decides what happens to a job, and tells the driver about it.
 *
 * The split from the driver is the load-bearing part of this design. The driver stores
 * envelopes; this class turns a job object into an envelope, works out whether a failure is
 * worth another attempt, and turns the outcome back into a storage transition. Neither half is
 * much on its own, and each is testable without the other — the driver contract test needs no
 * jobs at all, and the tests here need no storage.
 *
 * Delivery is at-least-once and cannot be anything else without a distributed transaction across
 * the queue and whatever the job itself did. A worker that dies after the work is done but before
 * it is recorded will run it again. Jobs are therefore expected to be idempotent, and the lease
 * in JobEnvelope is what bounds the duplicates rather than removing them.
 *
 * @package Phue\Modules\Queue
 */
final class QueueManager
{
    /** @var array<string, JobAttribute> Merged #[Job] values, computed once per class. */
    private array $defaults = [];

    /** @var list<string> Why a job's own overrides could not be honoured. */
    private array $configProblems = [];

    /** Distinguishes the claims this process makes from each other. */
    private int $workerSequence = 0;

    /**
     * @param QueueDriverInterface $driver Where jobs are stored.
     * @param QueueConfig          $config Validated settings.
     * @param PayloadCodec|null           $codec Encoding for job state; the default is the
     *                                           constructor-reflection one.
     * @param ResettableInterface|null     $afterExecution Reset between a job finishing and its
     *                                           outcome being recorded. See resetAfterExecution().
     */
    public function __construct(
        private readonly QueueDriverInterface $driver,
        private readonly QueueConfig $config,
        private ?PayloadCodec $codec = null,
        private readonly ?ResettableInterface $afterExecution = null,
    ) {
    }

    /**
     * The driver this manager writes through.
     */
    public function driver(): QueueDriverInterface
    {
        return $this->driver;
    }

    /**
     * Queue a job, running it now if the driver executes inline.
     *
     * Every reason this can fail is checked here, while the developer is looking at the code
     * that caused it. A queue that accepts a job it cannot encode, or that ignores the delay it
     * was given, defers both failures to a worker hours later with nothing in the stack trace to
     * connect them back to the enqueue site.
     *
     * @param JobInterface $job  The work to do.
     * @param string|null  $queue Overrides the job's #[Job] attribute and the configured default.
     * @param int          $delay Seconds before the job may run.
     *
     * @return string The id the driver stored it under.
     *
     * @throws \InvalidArgumentException     When the delay or queue name is not usable.
     * @throws QueuePayloadException         When the job cannot be encoded, is too large, is not
     *                                       loadable, or the driver cannot honour the delay.
     */
    public function push(JobInterface $job, ?string $queue = null, int $delay = 0): string
    {
        if ($delay < 0) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot queue %s with a delay of %d seconds; a delay cannot be negative.',
                $job::class,
                $delay
            ));
        }

        if ($delay > 0 && !$this->driver->supportsDelayed()) {
            throw new QueuePayloadException(sprintf(
                'Cannot queue %s %d seconds from now: the "%s" driver runs jobs the moment they '
                . 'are pushed and has nowhere to keep a job until then. Point queue.driver at a '
                . 'driver that supports delays, or push with no delay.',
                $job::class,
                $delay,
                $this->driver->name()
            ));
        }

        if ($queue !== null && !QueueConfig::isValidQueueName($queue)) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot queue %s: "%s" is not a legal queue name (%s).',
                $job::class,
                $queue,
                QueueConfig::QUEUE_PATTERN
            ));
        }

        $this->assertJobIsLoadable($job::class);

        $payload = $this->codec()->encode($job);
        $this->assertPayloadFits($job, $payload);

        $defaults = $this->defaultsFor($job::class);
        $envelope = JobEnvelope::forJob(
            jobClass: $job::class,
            payload: $payload,
            queue: $queue ?? $defaults->queue ?? $this->config->queue,
            availableAt: time() + $delay
        );

        $id = $this->driver->push($envelope);

        // An inline driver has already run the job by the time push() returns, so anything the
        // manager did not do at push time will not happen at all. Leaving that to a worker would
        // mean the default driver silently never retries and never dead-letters anything.
        if ($this->driver->executionModel() === ExecutionModel::Inline) {
            $this->runNow($envelope->queue);
        }

        return $id;
    }

    /**
     * Queue a job to run later.
     *
     * @param JobInterface $job   The work to do.
     * @param int          $delay Seconds from now. Must be positive to mean anything.
     * @param string|null  $queue Overrides the job's own queue.
     *
     * @return string The id the driver stored it under.
     */
    public function later(JobInterface $job, int $delay, ?string $queue = null): string
    {
        return $this->push($job, $queue, $delay);
    }

    /**
     * Claim one job, leaving it reserved for this worker.
     *
     * @param string|null $queue One queue, or null for any.
     * @param string|null $workerId Defaults to this process's next claim id.
     *
     * @return JobEnvelope|null The claimed job, or null when there was nothing to claim.
     */
    public function reserve(?string $queue = null, ?string $workerId = null): ?JobEnvelope
    {
        return $this->driver->reserve(
            $queue,
            $workerId ?? $this->workerId(),
            $this->config->leaseSeconds,
            time()
        );
    }

    /**
     * Claim and run one job to completion.
     *
     * The entire lifecycle in one method, so that a worker loop is `while ($this->runNext())`
     * and a test is a single call. Spreading this across the worker, the driver and the console
     * command is three places to keep the retry policy consistent.
     *
     * @param string|null $queue One queue, or null for any.
     *
     * @return JobOutcome|null How the attempt ended, or null when there was nothing to claim.
     */
    public function processNext(?string $queue = null, ?string $workerId = null): ?JobOutcome
    {
        // Generated once and used for both the claim and the outcome. Deriving it twice would
        // hand the driver one owner and the finishing call another, and every finish would be
        // refused by the driver's own ownership check — the job would run and then silently never
        // be recorded.
        $workerId ??= $this->workerId();
        $envelope = $this->reserve($queue, $workerId);

        if ($envelope === null) {
            return null;
        }

        try {
            return $this->run($envelope, $workerId);
        } catch (\Throwable $failure) {
            // run() is written not to throw. This is the belt to its braces, and it is here rather
            // than in the worker because the worker would have no way to record what happened.
            return $this->recordUnexpected($envelope, $workerId, $failure);
        }
    }

    /**
     * Claim and run one job, discarding how it ended.
     *
     * The convenience form of processNext() for callers that only care whether there was work —
     * a script draining once, a test asserting the queue was emptied.
     *
     * @param string|null $queue     One queue, or null for any.
     * @param string|null $workerId Who claims the job; generated when absent.
     *
     * @return bool False when there was nothing to claim.
     */
    public function runNext(?string $queue = null, ?string $workerId = null): bool
    {
        // Generated once and used for both the claim and the outcome. Deriving it twice would
        // hand the driver one owner and the finishing call another, and every finish would be
        // refused by the driver's own ownership check — the job would run and then silently never
        // be recorded.
        $workerId ??= $this->workerId();
        $envelope = $this->reserve($queue, $workerId);

        if ($envelope === null) {
            return false;
        }

        $this->run($envelope, $workerId);

        return true;
    }

    /**
     * Run the oldest claimable job on a queue, claiming it first.
     *
     * This is what an inline driver uses: the job was stored pending by push(), and it is claimed
     * through the driver like any other so that the ownership check the driver enforces on
     * completion is the same one in play as in a worker.
     */
    private function runNow(string $queue): void
    {
        $workerId = $this->workerId();
        $envelope = $this->reserve($queue, $workerId);

        if ($envelope === null) {
            return;
        }

        $this->run($envelope, $workerId);
    }

    /**
     * Execute a job the driver has handed out, and record the outcome.
     *
     * The claim is assumed to belong to $workerId. Completion is not assumed to succeed: if the
     * lease expired while handle() was running, another worker may own this job now, and the
     * driver's comparison decides that rather than this method.
     *
     * @param JobEnvelope $envelope As returned by reserve(), or as stored for an inline run.
     *
     * @return JobOutcome How the attempt ended.
     */
    public function run(JobEnvelope $envelope, ?string $workerId = null): JobOutcome
    {
        $workerId ??= $this->workerId();

        // Before anything reflects on the class. Reading its #[Job] attribute means constructing a
        // ReflectionClass over a name that came out of storage, and a name that is not there makes
        // that throw a ReflectionException from inside the framework — which, before this check
        // existed, escaped every catch in the method and left the job reserved forever with nobody
        // to hand it back.
        try {
            $this->assertJobIsLoadable($envelope->jobClass, $envelope->id, $envelope->queue);
        } catch (\Throwable $failure) {
            // Dead-lettered rather than retried. The payload names a class this release cannot
            // load, so no attempt of this job will ever succeed here; the failure record is what
            // makes that visible, and its message says exactly which class is missing.
            $this->driver->fail($envelope, $workerId, $failure, false);

            return JobOutcome::Failed;
        }

        $defaults = $this->defaultsFor($envelope->jobClass);
        $maxTries = $defaults->tries ?? $this->config->maxTries;
        $context = JobContext::forAttempt(
            $envelope,
            maxTries: $maxTries,
            timeout: $defaults->timeout ?? $this->config->leaseSeconds,
            workerId: $workerId
        );

        try {
            $this->hydrate($envelope)->handle($context);
            $this->resetAfterExecution(null);
            $this->driver->complete($envelope, $workerId);

            return JobOutcome::Completed;
        } catch (JobReleasedException $released) {
            $this->driver->release($envelope, $workerId, $released->delaySeconds);

            return JobOutcome::Retried;
        } catch (JobFailedException | NonRetryableException $failure) {
            $this->driver->fail($envelope, $workerId, $failure, false);

            return JobOutcome::Failed;
        } catch (\Throwable $failure) {
            $this->resetAfterExecution($failure);

            if ($this->willRetry($envelope, $context)) {
                $this->driver->release(
                    $envelope,
                    $workerId,
                    $this->backoffFor($envelope, $defaults)
                );

                return JobOutcome::Retried;
            }

            $this->driver->fail($envelope, $workerId, $failure, false);

            return JobOutcome::Failed;
        }
    }

    /**
     * The outermost guarantee of this method: it does not throw.
     *
     * Everything above handles the failures it knows about. This handles the ones nobody thought
     * of, because the alternative is not a crash — it is a job left reserved with no worker
     * accounting for it, which surfaces hours later as a queue that has mysteriously stopped
     * making progress.
     *
     * @param \Throwable $failure Anything at all.
     */
    private function recordUnexpected(JobEnvelope $envelope, string $workerId, \Throwable $failure): JobOutcome
    {
        try {
            $this->driver->fail($envelope, $workerId, $failure, false);
        } catch (\Throwable) {
            // The store itself is refusing writes. Nothing can be recorded, and saying so would be
            // the only honest thing left — but throwing here would strand the job silently, which is
            // the outcome this whole method exists to prevent.
            return JobOutcome::Failed;
        }

        return JobOutcome::Failed;
    }

    /**
     * Roll back whatever the job left open, before its outcome is recorded.
     *
     * This ordering is the whole reason it exists. A job that throws while holding a transaction
     * open has that transaction still open when the manager records the result, so the recording —
     * a release, or a completion — is written inside it and disappears with the rollback. The job
     * is then neither completed nor released: it sits reserved until its lease runs out and is run
     * again, having already done whatever it did. Nothing reports an error.
     *
     * The transaction is ended here rather than by the worker because the worker cannot: the
     * manager owns both halves of the sequence, and only it can put a boundary between a job
     * finishing and its outcome being written.
     *
     * Committed work is untouched — reset() rolls back an open transaction and does nothing to a
     * closed one — so a job that manages its own transactions correctly is unaffected.
     *
     * @param \Throwable|null $failure What the job threw, if anything. Recorded for the caller.
     */
    private function resetAfterExecution(?\Throwable $failure): void
    {
        $this->afterExecution?->reset();
    }

    /**
     * Whether a thrown failure deserves another attempt.
     *
     * Inline drivers never retry, and that is a decision rather than an omission. A job released
     * by an inline driver has no worker to pick it up — the push that created it has already
     * returned — so a retry would sit in the store until something else happened to run. Failing
     * it immediately puts the real reason in the dead-letter store, where a developer running in
     * development will actually see it, instead of leaving a permanently pending job behind.
     */
    private function willRetry(JobEnvelope $envelope, JobContext $context): bool
    {
        if ($this->driver->executionModel() === ExecutionModel::Inline) {
            return false;
        }

        return !$envelope->isFinalAttempt($context->maxTries);
    }

    /**
     * Rebuild the job an envelope describes.
     *
     * The stored payload is data, not code: it names a class, and the name is checked against
     * the configured namespaces before anything is instantiated. Without that check, anyone who
     * can write a row in the queue table chooses which class a worker constructs, which is a far
     * larger capability than "can enqueue a job".
     *
     * @throws QueuePayloadException When the class is unknown, is not a job, or is outside the
     *                               configured namespaces.
     */
    public function hydrate(JobEnvelope $envelope): JobInterface
    {
        $this->assertJobIsLoadable($envelope->jobClass, $envelope->id, $envelope->queue);

        return $this->codec()->decode($envelope->jobClass, $envelope->payload);
    }

    /**
     * Counts per status, straight from the driver.
     *
     * @param string|null $queue One queue, or null for everything.
     *
     * @return array{pending: int, reserved: int, completed: int, failed: int, total: int}
     */
    public function stats(?string $queue = null): array
    {
        return $this->driver->stats($queue);
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
        return $this->driver->prune($completedOlderThanSeconds);
    }

    /**
     * The name this process claims work under.
     *
     * A sequence number as well as the pid, so two claims by the same process are distinguishable
     * in a log and a second process reusing a pid cannot be mistaken for the first.
     */
    public function workerId(): string
    {
        $pid = function_exists('getmypid') ? (int) getmypid() : 0;

        return sprintf('w%s-%d', $pid, ++$this->workerSequence);
    }

    /**
     * Return jobs whose lease ran out to the pending list.
     *
     * Only meaningful on a driver that has a lease to expire. An inline driver has no worker
     * holding anything, so this reports how many it reclaimed — zero, honestly — instead of
     * quietly doing nothing and letting a caller believe the queue was swept.
     *
     * @param int      $graceSeconds Extra hold past the expiry, so a slow job is not stolen from
     *                               a worker that is still running it.
     * @param int|null $now          Current time, injectable so tests need not sleep.
     *
     * @return int How many jobs were returned to the queue.
     */
    public function reclaimExpiredLeases(int $graceSeconds = 0, ?int $now = null): int
    {
        if (!$this->driver instanceof DatabaseQueueDriver) {
            return 0;
        }

        return $this->driver->reclaimExpiredLeases($graceSeconds, $now);
    }

    /**
     * The #[Job] attribute merged over config, computed once per class.
     *
     * Cached because it is read on every retry decision, and reflecting on each attempt to
     * recover a value that cannot change is a cost that only ever shows up under load.
     */
    private function defaultsFor(string $jobClass): JobAttribute
    {
        return $this->defaults[$jobClass] ??= JobAttribute::of($jobClass)
            ->resolve($jobClass, $this->config, $this->configProblems);
    }

    /**
     * How long to wait before this job's next attempt.
     *
     * The job's own #[Job] backoff wins over the configured curve, because a job that knows it is
     * rate limited has better information than a global setting. A list is indexed by the attempt
     * that just failed and repeats its last entry once exhausted, so a three-step ladder still
     * has an answer on the fifth attempt.
     */
    private function backoffFor(JobEnvelope $envelope, ?JobAttribute $defaults = null): int
    {
        $backoff = ($defaults ?? $this->defaultsFor($envelope->jobClass))->backoff;

        if (is_int($backoff)) {
            return max(0, $backoff);
        }

        if (is_array($backoff) && $backoff !== []) {
            $step = $backoff[max(0, $envelope->attempts - 1)] ?? $backoff[array_key_last($backoff)];

            return max(0, (int) $step);
        }

        return $this->config->backoffDelay($envelope->id, $envelope->attempts);
    }

    /**
     * Reject a payload the store would not be able to carry back.
     *
     * @param array<string, mixed> $payload
     *
     * @throws QueuePayloadException When the payload will not encode, or is over the limit.
     */
    private function assertPayloadFits(JobInterface $job, array $payload): void
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        if (strlen($encoded) > $this->config->maxPayloadBytes) {
            throw QueuePayloadException::oversizedPayload(
                $job,
                strlen($encoded),
                $this->config->maxPayloadBytes
            );
        }
    }

    /**
     * Refuse a job class a worker would not be allowed to instantiate.
     *
     * Checked at push time as well as at run time. Failing at push is better, because the
     * developer is present; failing at run is the backstop for rows written by an older release,
     * a replay, or a hand.
     *
     * @throws QueuePayloadException When the class is unknown, is not a job, or is outside the
     *                               configured namespaces.
     */
    private function assertJobIsLoadable(string $jobClass, string $jobId = '', string $queue = ''): void
    {
        if (!class_exists($jobClass)) {
            throw QueuePayloadException::unknownJobClass($jobClass, $jobId, $queue);
        }

        if (!is_a($jobClass, JobInterface::class, true)) {
            throw QueuePayloadException::notAJob($jobClass, $jobId, $queue);
        }

        $namespaces = $this->config->jobNamespaces;

        if ($namespaces === []) {
            return;
        }

        foreach ($namespaces as $namespace) {
            if ($jobClass === $namespace || str_starts_with($jobClass, $namespace . '\\')) {
                return;
            }
        }

        throw QueuePayloadException::disallowedJobClass($jobClass, $namespaces);
    }

    /**
     * The codec this manager encodes and decodes with.
     *
     * Published because a caller that has a job to rehydrate should not have to construct a
     * second codec and thereby a second reflection cache.
     */
    public function codec(): PayloadCodec
    {
        return $this->codec ??= new PayloadCodec();
    }
}