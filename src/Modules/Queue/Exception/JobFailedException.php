<?php

declare(strict_types=1);

namespace Phue\Modules\Queue\Exception;

/**
 * Control flow: the job declared itself terminally failed.
 *
 * Distinct from letting an exception escape handle(), which asks the retry policy to decide.
 * Thrown from JobContext::fail() it means "no amount of retrying fixes this", and the manager
 * skips the policy entirely.
 *
 * @package Phue\Modules\Queue\Exception
 */
final class JobFailedException extends \RuntimeException
{
    /**
     * @param \Throwable $reason What the job said was wrong, kept as the cause so the dead-letter
     *                           record shows the real problem rather than this wrapper.
     */
    public function __construct(\Throwable $reason)
    {
        parent::__construct('Job declared itself failed: ' . $reason->getMessage(), 0, $reason);
    }
}