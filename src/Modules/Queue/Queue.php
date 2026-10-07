<?php

declare(strict_types=1);

namespace Phue\Modules\Queue;

use Phue\Core\Container;

/**
 * The one line a developer actually needs: Queue::push($job).
 *
 * A static entry point rather than a dependency to inject, because the alternative is every
 * controller that queues something having to know how the queue is wired. It resolves the manager
 * from the container the application already publishes, so there is no global state of its own and
 * nothing to keep in sync.
 *
 * Chosen over a bare helper function like enqueue() for one reason: a function's origin is
 * invisible at the call site, while a class name is greppable and every IDE will offer completion
 * for it the moment the module is installed.
 *
 * @package Phue\Modules\Queue
 */
final class Queue
{
    /**
     * Override the manager, for tests that want a driver of their own.
     *
     * Null restores normal resolution. Only ever set this from a test — in an application the
     * manager comes from the container, which is the only place that knows the configuration.
     */
    private static ?QueueManager $override = null;

    /**
     * Replace the manager for the rest of the process.
     *
     * @param QueueManager|null $manager Null to go back to resolving from the container.
     */
    public static function swap(?QueueManager $manager = null): void
    {
        self::$override = $manager;
    }

    /**
     * The manager this facade is talking to.
     *
     * @throws \RuntimeException When the queue module is not loaded, which is a configuration
     *                           mistake rather than something to handle at the call site.
     */
    public static function manager(): QueueManager
    {
        if (self::$override instanceof QueueManager) {
            return self::$override;
        }

        $container = Container::getInstance();

        if ($container === null || !$container->has(QueueManager::class)) {
            throw self::unavailable();
        }

        // has() answers true for any class name it could construct, and constructing a manager
        // without a driver behind it fails deep inside the container. The caller is standing at a
        // `Queue::push()` in their own code, so what they need is the sentence that tells them
        // which file to open — not a stack trace about interface instantiation.
        try {
            $manager = $container->get(QueueManager::class);
        } catch (\Throwable $e) {
            throw self::unavailable($e);
        }

        if (!$manager instanceof QueueManager) {
            throw new \RuntimeException(sprintf(
                'The container has a %s binding that is not a %s.',
                QueueManager::class,
                QueueManager::class
            ));
        }

        return $manager;
    }

    /**
     * The one error every caller of this class can hit, said the same way every time.
     */
    private static function unavailable(?\Throwable $previous = null): \RuntimeException
    {
        return new \RuntimeException(
            'The queue is not available. Add \Phue\Modules\Queue\QueueModule::class to the '
            . 'modules array in config/app.php.',
            0,
            $previous
        );
    }

    /**
     * Queue a job.
     *
     * @param JobInterface $job   The work to do.
     * @param string|null  $queue Overrides the job's #[Job] attribute and the configured default.
     * @param int          $delay Seconds before the job may run. Zero with the default driver
     *                           runs it right now, which is what development wants.
     *
     * @return string The id the job was stored under.
     */
    public static function push(JobInterface $job, ?string $queue = null, int $delay = 0): string
    {
        return self::manager()->push($job, $queue, $delay);
    }

    /**
     * Queue a job to run later.
     *
     * @param JobInterface $job   The work to do.
     * @param int          $delay Seconds from now.
     * @param string|null  $queue Overrides the job's own queue.
     *
     * @return string The id the job was stored under.
     *
     * @throws Exception\QueuePayloadException When the driver cannot honour a delay.
     */
    public static function later(JobInterface $job, int $delay, ?string $queue = null): string
    {
        return self::manager()->later($job, $delay, $queue);
    }

    /**
     * Counts per status.
     *
     * @param string|null $queue One queue, or null for everything.
     *
     * @return array{pending: int, reserved: int, completed: int, failed: int, total: int}
     */
    public static function stats(?string $queue = null): array
    {
        return self::manager()->stats($queue);
    }
}