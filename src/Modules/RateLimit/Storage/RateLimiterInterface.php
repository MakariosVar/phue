<?php

declare(strict_types=1);

namespace Phue\Modules\RateLimit\Storage;

use Phue\Modules\RateLimit\Policy\RateLimitPolicy;

/**
 * Interface for rate limiter storage backends.
 *
 * @package Phue\Modules\RateLimit\Storage
 */
interface RateLimiterInterface
{
    /**
     * Check and increment the rate limit counter.
     *
     * @param string            $key     Unique identifier for the rate limit bucket
     * @param RateLimitPolicy   $policy  Rate limit policy configuration
     * @return RateLimitResult Result containing limit info and whether allowed
     */
    public function check(string $key, RateLimitPolicy $policy): RateLimitResult;

    /**
     * Reset/clear the rate limit for a key.
     *
     * @param string $key Unique identifier
     * @return bool True if cleared, false if key didn't exist
     */
    public function reset(string $key): bool;

    /**
     * Get current usage without incrementing.
     *
     * @param string          $key    Unique identifier
     * @param RateLimitPolicy $policy Rate limit policy
     * @return RateLimitResult Current usage info
     */
    public function peek(string $key, RateLimitPolicy $policy): RateLimitResult;

    /**
     * Clear all rate limit data (use with caution).
     */
    public function clearAll(): void;
}