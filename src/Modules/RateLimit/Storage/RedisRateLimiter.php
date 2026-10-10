<?php

declare(strict_types=1);

namespace Phue\Modules\RateLimit\Storage;

use Phue\Modules\RateLimit\Policy\RateLimitPolicy;
use Redis;

/**
 * Redis-backed rate limiter using Lua scripts for atomicity.
 *
 * Supports two algorithms:
 * - sliding_window: Uses sorted sets (ZSET) with timestamps
 * - token_bucket: Uses hash with tokens and last_refill timestamp
 *
 * @package Phue\Modules\RateLimit\Storage
 */
final class RedisRateLimiter implements RateLimiterInterface
{
    /**
     * Lua script for sliding window algorithm.
     * Returns: [allowed (0/1), current_count, reset_timestamp, retry_after]
     */
    private const SLIDING_WINDOW_SCRIPT = <<<'LUA'
local key = KEYS[1]
local limit = tonumber(ARGV[1])
local window = tonumber(ARGV[2])
local now = tonumber(ARGV[3])
local ttl = window + 1

-- Remove expired entries (now is in milliseconds, window in seconds)
redis.call('ZREMRANGEBYSCORE', key, '-inf', now - window * 1000)

-- Count current requests in window
local count = redis.call('ZCARD', key)

if count >= limit then
    -- Rate limited: get oldest entry to calculate retry-after
    local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
    local reset = 0
    local retry_after = window
    if #oldest > 0 then
        -- oldest[2] is score in milliseconds
        reset = math.ceil(oldest[2] / 1000) + window
        retry_after = math.max(1, reset - now / 1000)
    else
        reset = math.ceil(now / 1000) + window
    end
    return {0, count, reset, retry_after}
end

-- Add current request (score in milliseconds)
local score = now .. '.' .. math.random(1000000)
redis.call('ZADD', key, score, score)
redis.call('EXPIRE', key, ttl)

local reset = math.ceil(now / 1000) + window
return {1, count + 1, reset, 0}
LUA;

    /**
     * Lua script for token bucket algorithm.
     * Returns: [allowed (0/1), tokens_remaining, reset_timestamp, retry_after]
     */
    private const TOKEN_BUCKET_SCRIPT = <<<'LUA'
local key = KEYS[1]
local capacity = tonumber(ARGV[1])
local refill_rate = tonumber(ARGV[2]) -- tokens per second
local now = tonumber(ARGV[3])
local ttl = math.ceil(capacity / refill_rate) + 10

local bucket = redis.call('HMGET', key, 'tokens', 'last_refill')
local tokens = tonumber(bucket[1])
local last_refill = tonumber(bucket[2])

if tokens == nil then
    tokens = capacity
    last_refill = now
end

-- Calculate tokens to add based on time elapsed
local elapsed = now - last_refill
local new_tokens = math.min(capacity, tokens + (elapsed * refill_rate))

if new_tokens >= 1 then
    -- Allow request
    new_tokens = new_tokens - 1
    redis.call('HMSET', key, 'tokens', new_tokens, 'last_refill', now)
    redis.call('EXPIRE', key, ttl)
    local reset = now + math.ceil((capacity - new_tokens) / refill_rate)
    return {1, math.floor(new_tokens), reset, 0}
else
    -- Rate limited
    local retry_after = math.ceil((1 - new_tokens) / refill_rate)
    local reset = now + retry_after
    redis.call('HMSET', key, 'tokens', new_tokens, 'last_refill', now)
    redis.call('EXPIRE', key, ttl)
    return {0, 0, reset, retry_after}
end
LUA;

    /**
     * Lua script for peek (get current state without incrementing).
     */
    private const SLIDING_WINDOW_PEEK_SCRIPT = <<<'LUA'
local key = KEYS[1]
local limit = tonumber(ARGV[1])
local window = tonumber(ARGV[2])
local now = tonumber(ARGV[3])

redis.call('ZREMRANGEBYSCORE', key, '-inf', now - window * 1000)
local count = redis.call('ZCARD', key)
local reset = math.ceil(now / 1000) + window

if count >= limit then
    local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
    local retry_after = window
    if #oldest > 0 then
        reset = math.ceil(oldest[2] / 1000) + window
        retry_after = math.max(1, reset - now / 1000)
    end
    return {0, count, reset, retry_after}
end
return {1, count, reset, 0}
LUA;

    private const TOKEN_BUCKET_PEEK_SCRIPT = <<<'LUA'
local key = KEYS[1]
local capacity = tonumber(ARGV[1])
local refill_rate = tonumber(ARGV[2])
local now = tonumber(ARGV[3])

local bucket = redis.call('HMGET', key, 'tokens', 'last_refill')
local tokens = tonumber(bucket[1])
local last_refill = tonumber(bucket[2])

if tokens == nil then
    return {1, capacity, now + math.ceil(capacity / refill_rate), 0}
end

local elapsed = now - last_refill
local new_tokens = math.min(capacity, tokens + (elapsed * refill_rate))
local reset = now + math.ceil((capacity - new_tokens) / refill_rate)

if new_tokens >= 1 then
    return {1, math.floor(new_tokens), reset, 0}
else
    local retry_after = math.ceil((1 - new_tokens) / refill_rate)
    return {0, 0, reset, retry_after}
end
LUA;

    /**
     * @param Redis  $redis  Redis connection
     * @param string $prefix Key prefix
     */
    public function __construct(
        private Redis $redis,
        private string $prefix = 'ratelimit:'
    ) {
        // Register Lua scripts
        $this->redis->eval('return 1', [], 0); // warm up
        $this->shaSlidingWindow = $this->redis->script('LOAD', self::SLIDING_WINDOW_SCRIPT);
        $this->shaTokenBucket = $this->redis->script('LOAD', self::TOKEN_BUCKET_SCRIPT);
        $this->shaSlidingWindowPeek = $this->redis->script('LOAD', self::SLIDING_WINDOW_PEEK_SCRIPT);
        $this->shaTokenBucketPeek = $this->redis->script('LOAD', self::TOKEN_BUCKET_PEEK_SCRIPT);
    }

    private string $shaSlidingWindow = '';
    private string $shaTokenBucket = '';
    private string $shaSlidingWindowPeek = '';
    private string $shaTokenBucketPeek = '';

    /**
     * {@inheritDoc}
     */
    public function check(string $key, RateLimitPolicy $policy): RateLimitResult
    {
        $fullKey = $this->prefix . $key;
        $windowSec = $policy->getWindowSeconds();
        $now = (int) (microtime(true) * 1000); // milliseconds for sliding window

        if ($policy->algorithm === 'token_bucket') {
            return $this->checkTokenBucket($fullKey, $policy, $windowSec, $now);
        }

        return $this->checkSlidingWindow($fullKey, $policy, $windowSec, $now);
    }

    /**
     * Sliding window check using EVALSHA.
     */
    private function checkSlidingWindow(
        string $key,
        RateLimitPolicy $policy,
        int $windowSec,
        int $nowMs
    ): RateLimitResult {
        $args = [$key, $policy->requests, $windowSec, $nowMs];
        try {
            $result = $this->redis->evalSha($this->shaSlidingWindow, $args, 1);
        } catch (\RedisException) {
            // Script flushed, reload
            $this->shaSlidingWindow = $this->redis->script('LOAD', self::SLIDING_WINDOW_SCRIPT);
            $result = $this->redis->evalSha($this->shaSlidingWindow, $args, 1);
        }

        [$allowed, $count, $reset, $retryAfter] = $result;

        if ($allowed) {
            return RateLimitResult::allowed($key, $policy, $count, $reset);
        }

        return RateLimitResult::denied($key, $policy, $reset, $retryAfter);
    }

    /**
     * Token bucket check using EVALSHA.
     */
    private function checkTokenBucket(
        string $key,
        RateLimitPolicy $policy,
        int $windowSec,
        int $nowMs
    ): RateLimitResult {
        $nowSec = (int) ($nowMs / 1000);
        $refillRate = $policy->requests / $windowSec; // tokens per second
        $args = [$key, $policy->requests, $refillRate, $nowSec];

        try {
            $result = $this->redis->evalSha($this->shaTokenBucket, $args, 1);
        } catch (\RedisException) {
            $this->shaTokenBucket = $this->redis->script('LOAD', self::TOKEN_BUCKET_SCRIPT);
            $result = $this->redis->evalSha($this->shaTokenBucket, $args, 1);
        }

        [$allowed, $tokens, $reset, $retryAfter] = $result;

        if ($allowed) {
            return RateLimitResult::allowed($key, $policy, $policy->requests - $tokens, $reset);
        }

        return RateLimitResult::denied($key, $policy, $reset, $retryAfter);
    }

    /**
     * {@inheritDoc}
     */
    public function peek(string $key, RateLimitPolicy $policy): RateLimitResult
    {
        $fullKey = $this->prefix . $key;
        $windowSec = $policy->getWindowSeconds();
        $nowMs = (int) (microtime(true) * 1000);

        if ($policy->algorithm === 'token_bucket') {
            return $this->peekTokenBucket($fullKey, $policy, $windowSec, $nowMs);
        }

        return $this->peekSlidingWindow($fullKey, $policy, $windowSec, $nowMs);
    }

    private function peekSlidingWindow(
        string $key,
        RateLimitPolicy $policy,
        int $windowSec,
        int $nowMs
    ): RateLimitResult {
        $args = [$key, $policy->requests, $windowSec, $nowMs];
        try {
            $result = $this->redis->evalSha($this->shaSlidingWindowPeek, $args, 1);
        } catch (\RedisException) {
            $this->shaSlidingWindowPeek = $this->redis->script('LOAD', self::SLIDING_WINDOW_PEEK_SCRIPT);
            $result = $this->redis->evalSha($this->shaSlidingWindowPeek, $args, 1);
        }

        [$allowed, $count, $reset, $retryAfter] = $result;

        if ($allowed) {
            return RateLimitResult::allowed($key, $policy, $count, $reset);
        }

        return RateLimitResult::denied($key, $policy, $reset, $retryAfter);
    }

    private function peekTokenBucket(
        string $key,
        RateLimitPolicy $policy,
        int $windowSec,
        int $nowMs
    ): RateLimitResult {
        $nowSec = (int) ($nowMs / 1000);
        $refillRate = $policy->requests / $windowSec;
        $args = [$key, $policy->requests, $refillRate, $nowSec];

        try {
            $result = $this->redis->evalSha($this->shaTokenBucketPeek, $args, 1);
        } catch (\RedisException) {
            $this->shaTokenBucketPeek = $this->redis->script('LOAD', self::TOKEN_BUCKET_PEEK_SCRIPT);
            $result = $this->redis->evalSha($this->shaTokenBucketPeek, $args, 1);
        }

        [$allowed, $tokens, $reset, $retryAfter] = $result;

        if ($allowed) {
            return RateLimitResult::allowed($key, $policy, $policy->requests - $tokens, $reset);
        }

        return RateLimitResult::denied($key, $policy, $reset, $retryAfter);
    }

    /**
     * {@inheritDoc}
     */
    public function reset(string $key): bool
    {
        $fullKey = $this->prefix . $key;
        return $this->redis->del($fullKey) > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function clearAll(): void
    {
        $pattern = $this->prefix . '*';

        // Try SCAN first (production-safe)
        $cursor = '0';
        $foundKeys = false;
        do {
            $result = $this->redis->scan($cursor, $pattern, 100);
            if ($result === false) {
                break;
            }
            $cursor = $result[0];
            $keys = $result[1] ?? [];
            if (!empty($keys) && is_array($keys)) {
                $this->redis->del(...$keys);
                $foundKeys = true;
            }
        } while ($cursor !== '0' && $cursor !== 0);

        // Fallback to KEYS if SCAN found nothing (e.g., Redis version or config issue)
        if (!$foundKeys) {
            $keys = $this->redis->keys($pattern);
            if (!empty($keys) && is_array($keys)) {
                $this->redis->del(...$keys);
            }
        }
    }
}