<?php

declare(strict_types=1);

namespace Phue\Modules\Queue;

use Phue\Modules\Queue\Exception\QueuePayloadException;

/**
 * Turns a job object into values, and values back into a job object.
 *
 * Reflection over the constructor, because that is the only description of a job's state that
 * cannot drift: the constructor is what the job already has to keep correct for its own sake,
 * so deriving the payload from it removes a second, parallel thing to remember. The alternative
 * — toPayload()/deserializePayload() on every job — is twenty lines of boilerplate per job and a
 * silent wrong-recipient bug the first time somebody adds a constructor property and updates
 * one half of the pair.
 *
 * What is deliberately not supported: objects, resources and closures in a payload. A queued job
 * is carried between processes, so it can only carry values. That restriction is not a limitation
 * to work around — passing an identifier instead of a live model is the correct queue design,
 * because the model will have changed by the time the job runs, and this is where that gets
 * enforced instead of discovered.
 *
 * A job that genuinely needs a custom encoding implements toPayload()/fromPayload() as a pair,
 * and the codec refuses an asymmetric implementation rather than letting it fail at run time.
 *
 * @package Phue\Modules\Queue
 */
final class PayloadCodec
{
    /** @var array<string, \ReflectionClass<object>> */
    private array $reflections = [];

    /**
     * Values for this job, ready to be stored.
     *
     * @return array<string, mixed>
     */
    public function encode(JobInterface $job): array
    {
        $this->assertCodecIsSymmetric($job::class);

        if (method_exists($job, 'toPayload')) {
            return $this->assertEncodable($job, $this->callCustomEncoder($job));
        }

        return $this->assertEncodable($job, $this->fromConstructor($job));
    }

    /**
     * The job these values describe.
     *
     * @param array<string, mixed> $payload
     */
    public function decode(string $jobClass, array $payload): JobInterface
    {
        $this->assertCodecIsSymmetric($jobClass);

        if (!class_exists($jobClass)) {
            throw QueuePayloadException::unknownJobClass($jobClass);
        }

        if (!is_a($jobClass, JobInterface::class, true)) {
            throw QueuePayloadException::notAJob($jobClass);
        }

        if (!method_exists($jobClass, 'fromPayload')) {
            return $this->fromConstructorArguments($jobClass, $payload);
        }

        try {
            $job = $jobClass::fromPayload($payload);
        } catch (\Throwable $e) {
            throw QueuePayloadException::customDecoderFailed($jobClass, $e);
        }

        if (!$job instanceof JobInterface) {
            throw new QueuePayloadException(sprintf(
                '%s::fromPayload() returned %s, which is not a %s.',
                $jobClass,
                get_debug_type($job),
                JobInterface::class
            ));
        }

        return $job;
    }

    /**
     * Constructor arguments, in declaration order.
     *
     * Public visibility is required. A private constructor property cannot be read without
     * setAccessible(), and doing that to reach into somebody's encapsulation is the kind of
     * surprise a job author cannot debug from the outside.
     *
     * @return array<string, mixed>
     */
    private function fromConstructor(JobInterface $job): array
    {
        $class = new \ReflectionClass($job);
        $constructor = $class->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $payload = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (!$class->hasProperty($name)) {
                if ($parameter->isDefaultValueAvailable()) {
                    // A defaulted argument with no property behind it carries nothing: the value
                    // is in the signature and will be reapplied when the job is rebuilt.
                    continue;
                }

                throw QueuePayloadException::unencodableProperty($job, $name);
            }

            $property = $class->getProperty($name);

            if (!$property->isPublic() || !$property->isInitialized($job)) {
                throw QueuePayloadException::unencodableProperty($job, $name);
            }

            $value = $property->getValue($job);
            $this->assertArgumentEncodable($job, $name, $parameter, $value);
            $payload[$name] = $value;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fromConstructorArguments(string $jobClass, array $payload): JobInterface
    {
        $class = $this->reflectionFor($jobClass);
        $constructor = $class->getConstructor();

        if ($constructor === null) {
            return $class->newInstance();
        }

        $arguments = [];
        $missing = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (!array_key_exists($name, $payload)) {
                $missing[] = $name;
                continue;
            }

            $arguments[$name] = $payload[$name];
        }

        // Reported before instantiation, because newInstanceArgs() with a short array fails on
        // whichever parameter happens to be required first, which is not necessarily the one the
        // developer forgot to serialise.
        if ($missing !== []) {
            throw new QueuePayloadException(sprintf(
                'Stored payload for %s is missing %s. The constructor requires %s, so the payload '
                . 'was written by a different version of the job, or the property was renamed '
                . 'without a migration. Implement fromPayload() to accept both shapes.',
                $jobClass,
                '$' . implode(', $', $missing),
                '$' . implode(', $', array_map(
                    static fn(\ReflectionParameter $p): string => $p->getName(),
                    $constructor->getParameters()
                ))
            ));
        }

        try {
            $job = $class->newInstanceArgs($arguments);
        } catch (\Throwable $e) {
            throw new QueuePayloadException(sprintf(
                'Could not rebuild %s from its stored payload: %s',
                $jobClass,
                $e->getMessage()
            ), 0, $e);
        }

        if (!$job instanceof JobInterface) {
            throw QueuePayloadException::notAJob($jobClass);
        }

        return $job;
    }

    /**
     * @return array<string, mixed>
     */
    private function callCustomEncoder(JobInterface $job): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $job->toPayload();

        return $payload;
    }

    /**
     * Refuse a job that implements one half of the encoding pair without the other.
     *
     * Encoding without a matching decoder produces a payload nothing can turn back into a job, so
     * the asymmetry is caught here rather than discovered by a worker hours later.
     *
     * @param string $jobClass The job's class.
     *
     * @throws QueuePayloadException When only one half is present.
     */
    private function assertCodecIsSymmetric(string $jobClass): void
    {
        $hasTo = method_exists($jobClass, 'toPayload');
        $hasFrom = method_exists($jobClass, 'fromPayload');

        if ($hasTo && !$hasFrom) {
            throw QueuePayloadException::asymmetricCodec($jobClass, 'toPayload', 'fromPayload');
        }

        if ($hasFrom && !$hasTo) {
            throw QueuePayloadException::asymmetricCodec($jobClass, 'fromPayload', 'toPayload');
        }
    }

    /**
     * Refuse one constructor argument that could not survive the trip.
     *
     * @param JobInterface          $job       The job being encoded.
     * @param string                $name      Parameter at fault.
     * @param \ReflectionParameter   $parameter Its declaration, for the expected type.
     * @param mixed                 $value     What it currently holds.
     *
     * @throws QueuePayloadException When the value is not encodable.
     */
    private function assertArgumentEncodable(
        JobInterface $job,
        string $name,
        \ReflectionParameter $parameter,
        mixed $value
    ): void {
        if ($this->isEncodable($value)) {
            return;
        }

        throw QueuePayloadException::nonEncodableArgument(
            $job,
            $name,
            $parameter->hasType() ? (string) $parameter->getType() : 'mixed',
            get_debug_type($value)
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function assertEncodable(JobInterface $job, array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (!$this->isEncodable($value)) {
                throw QueuePayloadException::nonEncodableArgument(
                    $job,
                    (string) $key,
                    'mixed',
                    get_debug_type($value)
                );
            }
        }

        return $payload;
    }

    /**
     * Scalars, null, and arrays of the same.
     *
     * Rejecting objects is the whole design: a queue hands the payload to a constructor in
     * another process, and an object has no meaning there. Saying so at push time is the whole
     * reason this class exists.
     */
    private function isEncodable(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $entry) {
            if (!$this->isEncodable($entry)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param class-string $jobClass
     * @return \ReflectionClass<object>
     */
    private function reflectionFor(string $jobClass): \ReflectionClass
    {
        return $this->reflections[$jobClass] ??= new \ReflectionClass($jobClass);
    }
}