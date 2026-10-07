<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Commands;

use Phue\Modules\Queue\Driver\DatabaseQueueDriver;
use Phue\Modules\Queue\QueueConfig;
use Phue\Modules\Queue\Worker\PosixSignalWatcher;
use Phue\Modules\Queue\Worker\QueueWorker;

/**
 * Run jobs until the queue is empty, a limit is reached, or the process is asked to stop.
 *
 * Three modes, and the default is chosen against the wrong instinct. A worker that runs forever
 * needs a supervisor, which is an operational dependency with its own failure modes and no
 * equivalent on shared hosting. A worker that runs a bounded number of jobs and exits can be run
 * from cron and works everywhere, which is why `--max-jobs` is the default rather than `--daemon`.
 *
 *   queue:work                     bounded, for cron
 *   queue:work --once              exactly one job
 *   queue:work --daemon            forever, for a supervisor
 *   queue:work --queue=emails      one queue
 *   queue:work --max-jobs=500 --max-seconds=300
 *
 * @package Phue\Modules\Queue\Commands
 */
final class QueueWorkCommand extends AbstractQueueCommand
{
    /**
     * Jobs a bounded run will do before exiting, when nothing else says.
     *
     * A hundred is long enough that a burst is drained in one wake-up and short enough that a
     * process that has gone wrong is replaced by the next cron tick rather than living for an hour.
     */
    public const DEFAULT_MAX_JOBS = 100;

    /**
     * The console name this command is invoked by.
     */
    public function getName(): string
    {
        return 'queue:work';
    }

    /**
     * One line for the console's help output.
     */
    public function getDescription(): string
    {
        return 'Run queued jobs. Bounded by default for cron; --daemon runs forever under a supervisor.';
    }

    /**
     * The group this command is listed under.
     */
    public function getGroup(): string
    {
        return 'Queue';
    }

    /**
     * Work the queue.
     *
     * @param array<int, string> $args         Everything after the command name.
     * @param resource           $outputStream Where progress and the summary go.
     * @param resource           $errorStream  Where failures go.
     *
     * @return int 0 when the queue was drained or the limit reached, 1 on a configuration or
     *             connection failure.
     */
    public function execute(array $args = [], $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        $daemon = $this->flag($args, 'daemon');
        $once = $this->flag($args, 'once');

        try {
            $config = $this->queueConfig();

            if ($config->driver === 'sync') {
                // The inline driver runs jobs as they are pushed, so there is nothing here waiting
                // to be picked up. Saying so is far better than a worker that exits silently having
                // done nothing, which is indistinguishable from a healthy idle queue.
                fwrite($errorStream, sprintf(
                    "queue:work: nothing to work on. queue.driver is \"sync\", which runs each job "
                    . "as it is queued. Set QUEUE_DRIVER=database to have jobs wait for a worker.\n"
                ));

                return self::FAILURE;
            }

            $manager = $this->manager();
        } catch (\InvalidArgumentException $e) {
            return $this->fail($errorStream, 'queue:work', $e);
        } catch (\Throwable $e) {
            return $this->fail($errorStream, 'queue:work', $e);
        }

        $queue = $this->option($args, 'queue');

        if ($queue !== null && !QueueConfig::isValidQueueName($queue)) {
            return $this->fail(
                $errorStream,
                'queue:work',
                new \InvalidArgumentException(sprintf(
                    '--queue="%s" is not a legal queue name (%s).',
                    $queue,
                    QueueConfig::QUEUE_PATTERN
                ))
            );
        }

        try {
            $maxJobs = $this->intOption($args, 'max-jobs');
            $maxSeconds = $this->intOption($args, 'max-seconds');
        } catch (\InvalidArgumentException $e) {
            return $this->fail($errorStream, 'queue:work', $e);
        }

        if ($once) {
            $maxJobs = 1;
        }

        if ($daemon) {
            // A supervised worker runs until it is signalled. An unbounded run with neither a job
            // limit nor a time limit is what the user asked for by passing --daemon.
            $maxJobs ??= null;
        } else {
            $maxJobs ??= self::DEFAULT_MAX_JOBS;
        }

        $signals = new PosixSignalWatcher();
        $canStop = $signals->install();

        if ($daemon && !$canStop) {
            // Worth refusing rather than pretending: under a supervisor the stop arrives as a
            // SIGTERM, and a worker that cannot hear it will be killed mid-job every time the
            // supervisor restarts it. An operator needs to know that before they deploy it.
            fwrite($errorStream, sprintf(
                "queue:work: --daemon needs ext-pcntl to stop cleanly on SIGTERM, and it is not "
                . "loaded here. Use --max-jobs or --max-seconds instead, which is what cron wants "
                . "anyway.\n"
            ));

            return self::FAILURE;
        }

        $worker = new QueueWorker(
            manager: $manager,
            container: $this->container(),
            connection: $manager->driver() instanceof DatabaseQueueDriver
                ? $this->connection()
                : null,
            signals: $signals,
        );

        fwrite($outputStream, sprintf(
            "queue:work starting as %s (%s), %s%s\n",
            $manager->workerId(),
            $manager->driver()->name(),
            $queue === null ? 'all queues' : sprintf('queue "%s"', $queue),
            $daemon ? ', until signalled' : sprintf(', up to %d jobs', $maxJobs ?? 0)
        ));

        try {
            $report = $worker->run(
                queue: $queue,
                maxJobs: $maxJobs,
                maxSeconds: $maxSeconds,
                stopWhenEmpty: !$daemon
            );
        } catch (\Throwable $e) {
            return $this->fail($errorStream, 'queue:work', $e);
        }

        fwrite($outputStream, $report->summary() . "\n");

        return self::SUCCESS;
    }
}