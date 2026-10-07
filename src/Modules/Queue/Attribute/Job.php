<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Attribute;

use Phue\Modules\Queue\QueueConfig;

/**
 * Per-job overrides of the queue defaults.
 *
 * Applied to a job class and every value is optional; leaving the attribute off entirely is a
 * valid way to say "use config/queue.php for everything". That is why it is an attribute rather
 * than a set of interface methods: an annotation you may omit is a declaration, while methods
 * that are part of the contract are requirements, and a job author should not have to think
 * about queue configuration at all to get their first job running.
 *
 * The parallel with #[JobPayload] on constructor parameters is worth stating, because it is the
 * opposite decision. Annotating what you want to CHANGE is good. Annotating every payload field
 * would be an annotation repeated on every job, for something the framework can already work
 * out, and forgetting one would silently drop a value the job then runs without.
 *
 * @package Phue\Modules\Queue\Attribute
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Job
{
    /**
     * @param string|null        $queue Queue name, or null for config queue.default.
     * @param int|null           $tries Attempts before the job is dead-lettered, or null for
     *                               config queue.max_tries.
     * @param int|null           $timeout Lease length in seconds. This is how long a worker may
     *                               hold the job before another worker may take it, not a hard
     *                               kill — see JobContext::timeout().
     * @param int|list<int>|null $backoff Seconds to wait before the next attempt, or a per
     *                               attempt list whose last entry repeats. Null means the
     *                               configured exponential curve.
     */
    public function __construct(
        public readonly ?string $queue = null,
        public readonly ?int $tries = null,
        public readonly ?int $timeout = null,
        public readonly int|array|null $backoff = null,
    ) {
    }

    /**
     * The attribute as written, or a stand-in carrying no overrides.
     *
     * Kept separate from resolve() because "what did this class ask for" and "what should the
     * driver do" are different questions, and conflating them makes the attribute's own values
     * unreportable.
     */
    public static function of(string $jobClass): self
    {
        $attributes = (new \ReflectionClass($jobClass))->getAttributes(self::class);

        if ($attributes === []) {
            return new self();
        }

        return $attributes[0]->newInstance();
    }

    /**
     * The attribute merged over the configuration defaults.
     *
     * This is what the runtime asks for; anything that validates a job's numbers before using
     * them validates the object this returns.
     *
     * @param list<string> $errors Collects the reasons this job could not be honoured, rather
     *                             than throwing, so one bad job cannot take down a worker.
     */
    public function resolve(string $jobClass, QueueConfig $config, array &$errors = []): self
    {
        $queue = $this->queue ?? $config->queue;

        if (!QueueConfig::isValidQueueName($queue)) {
            $errors[] = sprintf(
                '%s declares queue "%s", which is not a legal queue name (%s).',
                $jobClass,
                $queue,
                QueueConfig::QUEUE_PATTERN
            );
            $queue = $config->queue;
        }

        $tries = $this->tries ?? $config->maxTries;

        if ($tries < 1) {
            $errors[] = sprintf('%s declares tries: %d, which is not at least one attempt.', $jobClass, $tries);
            $tries = $config->maxTries;
        }

        $timeout = $this->timeout ?? $config->leaseSeconds;

        if ($timeout < 1) {
            $errors[] = sprintf(
                '%s declares timeout: %d, which would leave the job reserved for no time at all.',
                $jobClass,
                $timeout
            );
            $timeout = $config->leaseSeconds;
        }

        return new self($queue, $tries, $timeout, $this->backoff);
    }
}