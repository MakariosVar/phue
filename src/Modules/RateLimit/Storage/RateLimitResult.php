<?php

declare(strict_types=1);

namespace Phue\Modules\RateLimit\Storage;

use Phue\Modules\RateLimit\Policy\RateLimitPolicy;

/**
 * Result of a rate limit check.
 *
 * @package Phue\Modules\RateLimit\Storage
 */
final readonly class RateLimitResult
{
    /**
     * @param bool   $allowed       Whether the request is allowed
     * @param int    $limit         Maximum requests allowed in window
     * @param int    $remaining     Remaining requests in current window
     * @param int    $reset         Unix timestamp when the window resets
     * @param int    $retryAfter    Seconds until next request allowed (0 if allowed)
     * @param string $key           The rate limit key that was checked
     * @param RateLimitPolicy $policy The policy that was applied
     */
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $reset,
        public int $retryAfter,
        public string $key,
        public RateLimitPolicy $policy
    ) {}

    /**
     * Create a result for an allowed request.
     */
    public static function allowed(
        string $key,
        RateLimitPolicy $policy,
        int $used,
        int $reset
    ): self {
        $limit = $policy->requests;
        $remaining = max(0, $limit - $used);
        return new self(
            allowed: true,
            limit: $limit,
            remaining: $remaining,
            reset: $reset,
            retryAfter: 0,
            key: $key,
            policy: $policy
        );
    }

    /**
     * Create a result for a denied request.
     */
    public static function denied(
        string $key,
        RateLimitPolicy $policy,
        int $reset,
        int $retryAfter
    ): self {
        return new self(
            allowed: false,
            limit: $policy->requests,
            remaining: 0,
            reset: $reset,
            retryAfter: $retryAfter,
            key: $key,
            policy: $policy
        );
    }

    /**
     * Convert to response headers array.
     *
     * @return array<string, string>
     */
    public function toHeaders(): array
    {
        return [
            'X-RateLimit-Limit'     => (string) $this->limit,
            'X-RateLimit-Remaining' => (string) $this->remaining,
            'X-RateLimit-Reset'     => (string) $this->reset,
            'Retry-After'           => (string) $this->retryAfter,
        ];
    }
}