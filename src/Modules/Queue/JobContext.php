<?php

declare(strict_types=1);

namespace Phue\Modules\Queue;

use Phue\Modules\Queue\Exception\JobFailedException;
use Phue\Modules\Queue\Exception\JobReleasedException;

/**
 * What an attempt knows about itself, plus the channel for changing its own fate.
 *
 * Passed to handle() so a job can read its attempt number without reaching into storage, and so
 * it can give up or come back later on purpose. release() and fail() throw rather than set a
 * flag, because a job that calls release() and then throws on the way out would otherwise leave
 * the worker holding two contradictory signals; the exception unwinds the whole attempt and the
 * first decision wins.
 *
 * @package Phue\Modules\Queue
 */
final class JobContext
{
    /** @var array<string, mixed> Attempt-scoped values, carried between attempts. */
    private array $bag;

    /**
     * @param array<string, mixed> $bag
     */
    private function __construct(
        public readonly string $jobId,
        public readonly string $queue,
        public readonly string $jobClass,
        public readonly int $attempts,
        public readonly int $maxTries,
        public readonly int $timeout,
        public readonly string $workerId,
        private readonly bool $underWorker = false,
        array $bag = [],
    ) {
        $this->bag = $bag;
    }

    /**
     * A context for a job being run by a worker.
     *
     * @param int $maxTries From the #[Job] attribute or queue.max_tries.
     * @param int $timeout  Lease length in seconds, from #[Job] or queue.lease_seconds. How long
     *                      another worker may wait before taking this job over.
     */
    public static function forAttempt(
        JobEnvelope $envelope,
        int $maxTries,
        int $timeout,
        string $workerId
    ): self {
        return new self(
            jobId: $envelope->id,
            queue: $envelope->queue,
            jobClass: $envelope->jobClass,
            attempts: $envelope->attempts,
            maxTries: $maxTries,
            timeout: $timeout,
            workerId: $workerId,
            underWorker: true,
        );
    }

    /**
     * A context for running a job directly, as a unit test does.
     *
     * Exists so that a job can be tested by calling handle() with no framework involved — no
     * container, no database, no queue. That is the cheapest possible test of the only part of a
     * job anyone cares about.
     */
    public static function standalone(
        string $jobClass,
        string $queue = 'default',
        int $attempts = 1,
        int $maxTries = 3,
        string $workerId = 'standalone'
    ): self {
        return new self(
            jobId: '',
            queue: $queue,
            jobClass: $jobClass,
            attempts: $attempts,
            maxTries: $maxTries,
            timeout: 0,
            workerId: $workerId,
        );
    }

    /**
     * Put this job back on the queue for another attempt.
     *
     * @param int $delaySeconds Wait before it becomes claimable again. Zero means immediately,
     *                         which is usually wrong — a job that fails instantly and is retried
     *                         instantly is a busy loop, not a retry.
     */
    public function release(int $delaySeconds = 0): never
    {
        $this->assertUnderWorker('release');
        throw new JobReleasedException(max(0, $delaySeconds));
    }

    /**
     * Give up on this job now, whatever the retry policy would have said.
     *
     * @param \Throwable|null $reason What to record as the cause. Defaults to a plain exception
     *                                so the dead-letter row is never empty.
     */
    public function fail(?\Throwable $reason = null): never
    {
        $this->assertUnderWorker('fail');
        throw new JobFailedException($reason ?? new \RuntimeException('Job called $context->fail().'));
    }

    /**
     * True when failing this attempt ends the job.
     */
    /**
     * Whether failing this attempt ends the job, according to the configured attempt limit.
     */
    public function isFinalAttempt(): bool
    {
        return $this->attempts >= $this->maxTries;
    }

    /**
     * Carry a value to the next attempt of this job.
     *
     * Attempt-scoped, not persisted as it stands: it lives for as long as the manager holds this
     * context, so anything that has to outlive the attempt belongs in the job's own payload.
     *
     * @param string $key   Name to store under.
     * @param mixed  $value Value to store.
     *
     * @return mixed The value, so it can be used inline.
     */
    public function put(string $key, mixed $value): mixed
    {
        $this->bag[$key] = $value;

        return $value;
    }

    /**
     * Read a value carried by this attempt.
     *
     * @param string $key      Name to read.
     * @param mixed  $default  Returned when the key was never put.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->bag[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->bag;
    }

    /**
     * Refuse a control-flow call made outside a worker.
     *
     * @param string $method The method being called, for the message.
     *
     * @throws Exception\QueuePayloadException Always.
     */
    private function assertUnderWorker(string $method): void
    {
        if (!$this->underWorker) {
            throw Exception\QueuePayloadException::notInlineContext();
        }
    }
}