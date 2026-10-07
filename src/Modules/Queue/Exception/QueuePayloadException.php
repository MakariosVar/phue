<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Exception;

use Phue\Modules\Queue\JobInterface;

/**
 * Raised when a job cannot be turned into a payload, or a payload cannot be turned back into a job.
 *
 * Every one of these is thrown at push time, while the developer is looking at the code that
 * caused it, rather than hours later in a worker. That is the entire reason the message carries
 * the class name, the parameter name and the type that was actually found: a queue that fails
 * at enqueue with a precise complaint is a queue you debug in one minute.
 *
 * @package Phue\Modules\Queue\Exception
 */
final class QueuePayloadException extends \RuntimeException
{
    /**
     * A constructor argument holds something that cannot cross a process boundary. The message
     * says what to pass instead, because the fix is one line and not always obvious to someone
     * seeing this for the first time.
     *
     * @param JobInterface|string $job     The job, or its class name.
     * @param string              $parameter Constructor parameter at fault.
     * @param string              $expected What the signature declares.
     * @param string              $actual   What the caller actually supplied.
     */
    public static function nonEncodableArgument(
        JobInterface|string $job,
        string $parameter,
        string $expected,
        string $actual
    ): self {
        $class = is_object($job) ? $job::class : $job;

        return new self(sprintf(
            '%s::__construct parameter $%s expects %s but was given %s. Queued jobs are carried '
            . 'between processes, so they can only carry values: pass an identifier such as '
            . '$user->id rather than the object itself, or implement toPayload() on the job to '
            . 'encode it yourself.',
            $class,
            $parameter,
            $expected,
            $actual
        ));
    }

    /**
     * A constructor parameter has no readable property, so there is nothing to carry.
     *
     * @param JobInterface|string $job      The job, or its class name.
     * @param string              $parameter Constructor parameter at fault.
     */
    public static function unencodableProperty(
        JobInterface|string $job,
        string $parameter
    ): self {
        $class = is_object($job) ? $job::class : $job;

        return new self(sprintf(
            '%s::__construct requires $%s but the class has no matching property to read it '
            . 'from, so there is nothing to carry across processes. Promote the property '
            . '(public readonly), or implement toPayload() on the job.',
            $class,
            $parameter
        ));
    }

    /**
     * A custom toPayload() returned something that is not encodable.
     *
     * @param JobInterface|string $job    The job, or its class name.
     * @param string              $actual What json_encode objected to.
     */
    public static function unserializableValue(JobInterface|string $job, string $actual): self
    {
        $class = is_object($job) ? $job::class : $job;

        return new self(sprintf(
            'The payload of %s could not be encoded to JSON (%s). Implement toPayload() to '
            . 'return something encodable.',
            $class,
            $actual
        ));
    }

    /**
     * The payload is larger than the store is willing to carry.
     *
     * @param JobInterface|string $job   The job, or its class name.
     * @param int                 $bytes Encoded size.
     * @param int                 $limit Configured ceiling.
     */
    public static function oversizedPayload(JobInterface|string $job, int $bytes, int $limit): self
    {
        $class = is_object($job) ? $job::class : $job;

        return new self(sprintf(
            'The payload of %s is %d bytes, over the %d byte limit. Pass identifiers instead of '
            . 'inline blobs, or raise queue.max_payload_bytes.',
            $class,
            $bytes,
            $limit
        ));
    }

    /**
     * The stored payload names a class that is not there.
     *
     * @param string $jobClass Named in the payload.
     * @param string $jobId    Stored job id, when known.
     * @param string $queue    Stored queue, when known.
     */
    public static function unknownJobClass(string $jobClass, string $jobId = '', string $queue = ''): self
    {
        return new self(sprintf(
            'Cannot run job "%s": the class does not exist or is not autoloadable.%s',
            $jobClass,
            $jobId === '' ? '' : sprintf(
                ' The job id %s on queue "%s" has to be removed or replayed by hand.',
                $jobId,
                $queue
            )
        ));
    }

    /**
     * The stored payload names a class that exists but is not a job.
     *
     * @param string $jobClass Named in the payload.
     * @param string $jobId    Stored job id, when known.
     * @param string $queue    Stored queue, when known.
     */
    public static function notAJob(string $jobClass, string $jobId = '', string $queue = ''): self
    {
        return new self(sprintf(
            'Cannot run job "%s": it does not implement %s.%s',
            $jobClass,
            JobInterface::class,
            $jobId === '' ? '' : sprintf(' The job id %s on queue "%s".', $jobId, $queue)
        ));
    }

    /**
     * The class is outside the configured namespaces, so a worker will not instantiate it.
     *
     * @param string       $jobClass Named in the payload.
     * @param list<string> $allowed  Configured namespaces.
     */
    public static function disallowedJobClass(string $jobClass, array $allowed): self
    {
        return new self(sprintf(
            'Job class "%s" is not inside any configured queue.job_namespaces namespace (%s). '
            . 'A stored payload names the class to instantiate, so the list is the boundary that '
            . 'keeps a tampered queue table from turning into arbitrary class instantiation.',
            $jobClass,
            $allowed === [] ? 'none configured' : implode(', ', $allowed)
        ));
    }

    /**
     * The job implements one half of the encoding pair without the other.
     *
     * @param string $jobClass    The job's class.
     * @param string $implemented The half it does have.
     * @param string $missing     The half it does not.
     */
    public static function asymmetricCodec(string $jobClass, string $implemented, string $missing): self
    {
        return new self(sprintf(
            '%s implements %s() but not %s(). Encoding without the matching decoder produces a '
            . 'payload nothing can turn back into a job, so the asymmetry is refused rather '
            . 'than discovered at run time.',
            $jobClass,
            $implemented,
            $missing
        ));
    }

    /**
     * The job's own fromPayload() rejected the stored values.
     *
     * @param string     $jobClass The job's class.
     * @param \Throwable $reason   What it threw.
     */
    public static function customDecoderFailed(string $jobClass, \Throwable $reason): self
    {
        return new self(sprintf(
            '%s::fromPayload() rejected the stored payload: %s',
            $jobClass,
            $reason->getMessage()
        ), 0, $reason);
    }

    /**
     * release() or fail() was called outside a worker, which is always a mistake.
     */
    public static function notInlineContext(): self
    {
        return new self(
            'JobContext::release() and JobContext::fail() only mean something while a worker is '
            . 'running the job. Calling one from a unit test that invokes handle() directly '
            . 'throws; assert on the calls instead.'
        );
    }
}