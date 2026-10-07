<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Worker;

/**
 * What a worker run did, and why it stopped.
 *
 * Worth returning rather than printing, because a cron-invoked worker nobody is watching still has
 * to be able to answer the question after the fact, and because the reasons are what a test
 * asserts on: "ran out of jobs" and "was asked to stop" are very different outcomes and both look
 * like exit code 0 from the outside.
 *
 * @package Phue\Modules\Queue\Worker
 */
final readonly class WorkReport
{
    /**
     * @param int      $jobsRun        Jobs claimed and finished, successfully or not.
     * @param int      $jobsSucceeded  Jobs that completed.
     * @param int      $jobsFailed     Jobs that were dead-lettered.
     * @param int      $leasesReclaimed Expired reservations returned to the queue.
     * @param int      $iterations     Claim attempts made, including the empty ones.
     * @param string   $reason         Why the loop ended.
     * @param float    $seconds        Wall-clock time spent.
     */
    private function __construct(
        public int $jobsRun,
        public int $jobsSucceeded,
        public int $jobsFailed,
        public int $leasesReclaimed,
        public int $iterations,
        public string $reason,
        public float $seconds,
    ) {
    }

    /**
     * The loop reached an empty queue and stopped.
     *
     * Carries the counters like every other ending, because this is how a worker that drained the
     * queue reports itself. A factory that started from zero would make a worker that processed
     * fifty jobs and then found the queue empty report "0 run" — and that is the number an
     * operator looks at first.
     *
     * @param int   $jobsRun       Jobs finished.
     * @param int   $jobsSucceeded Jobs that completed.
     * @param int   $jobsFailed    Jobs that were dead-lettered.
     * @param int   $reclaimed     Expired reservations returned to the queue.
     * @param int   $iterations    Claim attempts made, including the one that found nothing.
     * @param float $seconds       Time spent.
     */
    public static function drained(
        int $jobsRun,
        int $jobsSucceeded,
        int $jobsFailed,
        int $reclaimed,
        int $iterations,
        float $seconds
    ): self {
        return new self(
            $jobsRun,
            $jobsSucceeded,
            $jobsFailed,
            $reclaimed,
            $iterations,
            self::REASON_DRAINED,
            $seconds
        );
    }

    /**
     * The loop hit its job limit.
     *
     * @param int   $jobsRun     Jobs finished.
     * @param int   $jobsSucceeded Jobs that completed.
     * @param int   $jobsFailed  Jobs that were dead-lettered.
     * @param int   $reclaimed   Expired reservations returned to the queue.
     * @param int   $iterations  Claim attempts made.
     * @param float $seconds     Time spent.
     */
    public static function jobLimit(
        int $jobsRun,
        int $jobsSucceeded,
        int $jobsFailed,
        int $reclaimed,
        int $iterations,
        float $seconds
    ): self {
        return new self(
            $jobsRun,
            $jobsSucceeded,
            $jobsFailed,
            $reclaimed,
            $iterations,
            self::REASON_JOB_LIMIT,
            $seconds
        );
    }

    /**
     * The loop hit its time limit.
     *
     * @param int   $jobsRun        Jobs finished.
     * @param int   $jobsSucceeded  Jobs that completed.
     * @param int   $jobsFailed     Jobs that were dead-lettered.
     * @param int   $reclaimed      Expired reservations returned to the queue.
     * @param int   $iterations     Claim attempts made.
     * @param float $seconds        Time spent.
     */
    public static function timeLimit(
        int $jobsRun,
        int $jobsSucceeded,
        int $jobsFailed,
        int $reclaimed,
        int $iterations,
        float $seconds
    ): self {
        return new self(
            $jobsRun,
            $jobsSucceeded,
            $jobsFailed,
            $reclaimed,
            $iterations,
            self::REASON_TIME_LIMIT,
            $seconds
        );
    }

    /**
     * Something asked the worker to stop.
     *
     * @param int   $jobsRun        Jobs finished.
     * @param int   $jobsSucceeded  Jobs that completed.
     * @param int   $jobsFailed     Jobs that were dead-lettered.
     * @param int   $reclaimed      Expired reservations returned to the queue.
     * @param int   $iterations     Claim attempts made.
     * @param float $seconds        Time spent.
     */
    public static function signalled(
        int $jobsRun,
        int $jobsSucceeded,
        int $jobsFailed,
        int $reclaimed,
        int $iterations,
        float $seconds
    ): self {
        return new self(
            $jobsRun,
            $jobsSucceeded,
            $jobsFailed,
            $reclaimed,
            $iterations,
            self::REASON_SIGNAL,
            $seconds
        );
    }

    /**
     * The worker was about to exhaust the memory limit.
     *
     * @param int   $jobsRun        Jobs finished.
     * @param int   $jobsSucceeded  Jobs that completed.
     * @param int   $jobsFailed     Jobs that were dead-lettered.
     * @param int   $reclaimed      Expired reservations returned to the queue.
     * @param int   $iterations     Claim attempts made.
     * @param float $seconds        Time spent.
     */
    public static function outOfMemory(
        int $jobsRun,
        int $jobsSucceeded,
        int $jobsFailed,
        int $reclaimed,
        int $iterations,
        float $seconds
    ): self {
        return new self(
            $jobsRun,
            $jobsSucceeded,
            $jobsFailed,
            $reclaimed,
            $iterations,
            self::REASON_MEMORY,
            $seconds
        );
    }

    /** Every claim found the queue empty. */
    public const REASON_DRAINED = 'drained';

    /** The configured number of jobs was reached. */
    public const REASON_JOB_LIMIT = 'job-limit';

    /** The configured amount of time was reached. */
    public const REASON_TIME_LIMIT = 'time-limit';

    /** A signal, or a caller, asked the worker to stop. */
    public const REASON_SIGNAL = 'signalled';

    /** The memory limit was close enough to be worth leaving for. */
    public const REASON_MEMORY = 'memory';

    /**
     * A one-line summary for a console or a log.
     */
    public function summary(): string
    {
        return sprintf(
            '%s: %d run (%d ok, %d failed), %d reclaimed, %d claims in %.2fs',
            $this->reason,
            $this->jobsRun,
            $this->jobsSucceeded,
            $this->jobsFailed,
            $this->leasesReclaimed,
            $this->iterations,
            $this->seconds
        );
    }
}