<?php

declare(strict_types=1);

namespace Phue\Modules\RateLimit\Exception;

use Phue\Modules\RateLimit\Policy\RateLimitPolicy;
use Phue\Modules\RateLimit\Storage\RateLimitResult;
use RuntimeException;

/**
 * Exception thrown when rate limit is exceeded.
 *
 * Can be caught to customize response or logging.
 *
 * @package Phue\Modules\RateLimit\Exception
 */
class RateLimitExceededException extends RuntimeException
{
    /**
     * @param RateLimitResult $result Rate limit check result
     * @param RateLimitPolicy $policy Applied policy
     */
    public function __construct(
        private RateLimitResult $result,
        private RateLimitPolicy $policy
    ) {
        parent::__construct(
            "Rate limit exceeded: {$result->limit} requests per {$policy->window}",
            429
        );
    }

    /**
     * Get the rate limit result.
     */
    public function getResult(): RateLimitResult
    {
        return $this->result;
    }

    /**
     * Get the applied policy.
     */
    public function getPolicy(): RateLimitPolicy
    {
        return $this->policy;
    }

    /**
     * Get retry-after seconds.
     */
    public function getRetryAfter(): int
    {
        return $this->result->retryAfter;
    }

    /**
     * Get rate limit headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->result->toHeaders();
    }
}