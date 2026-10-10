<?php

declare(strict_types=1);

namespace Phue\Tests\Modules\RateLimit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Modules\RateLimit\Policy\RateLimitPolicy;
use Phue\Modules\RateLimit\Storage\RateLimitResult;
use Phue\Modules\RateLimit\Storage\RedisRateLimiter;
use Phue\Tests\Support\RedisTestConnection;
use Redis;

#[Group('integration')]
#[Group('redis')]
class RedisRateLimiterTest extends TestCase
{
    private ?RedisTestConnection $harness = null;
    private ?RedisRateLimiter $limiter = null;
    private RateLimitPolicy $policy;

    protected function setUp(): void
    {
        $this->harness = RedisTestConnection::available();
        if ($this->harness === null) {
            $this->markTestSkipped('No Redis available; set PHUE_TEST_REDIS_HOST to run this suite.');
        }

        $this->limiter = new RedisRateLimiter($this->harness->redis, $this->harness->namespace . ':rl:');
        $this->policy = RateLimitPolicy::fromArray('test', [
            'requests' => 5,
            'window' => 'minute',
            'algorithm' => 'sliding_window',
            'key' => 'ip',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->harness !== null) {
            $this->harness->cleanup();
        }
    }

    public function testAllowsRequestsUnderLimit(): void
    {
        $key = 'test_ip_1';

        for ($i = 1; $i <= 5; $i++) {
            $result = $this->limiter->check($key, $this->policy);
            $this->assertTrue($result->allowed, "Request $i should be allowed");
            $this->assertEquals(5, $result->limit);
            $this->assertEquals(5 - $i, $result->remaining);
        }
    }

    public function testBlocksRequestsOverLimit(): void
    {
        $key = 'test_ip_2';

        // Use all 5 requests
        for ($i = 1; $i <= 5; $i++) {
            $result = $this->limiter->check($key, $this->policy);
            $this->assertTrue($result->allowed);
        }

        // 6th request should be blocked
        $result = $this->limiter->check($key, $this->policy);
        $this->assertFalse($result->allowed);
        $this->assertEquals(0, $result->remaining);
        $this->assertGreaterThan(0, $result->retryAfter);
    }

    public function testSeparateKeysIndependent(): void
    {
        $key1 = 'test_ip_3a';
        $key2 = 'test_ip_3b';

        // Use all requests for key1
        for ($i = 1; $i <= 5; $i++) {
            $this->assertTrue($this->limiter->check($key1, $this->policy)->allowed);
        }

        // key2 should still have full quota
        $result = $this->limiter->check($key2, $this->policy);
        $this->assertTrue($result->allowed);
        $this->assertEquals(4, $result->remaining);
    }

    public function testResetClearsKey(): void
    {
        $key = 'test_ip_4';

        for ($i = 1; $i <= 5; $i++) {
            $this->assertTrue($this->limiter->check($key, $this->policy)->allowed);
        }

        $this->assertFalse($this->limiter->check($key, $this->policy)->allowed);

        $this->assertTrue($this->limiter->reset($key));

        // Should be allowed again after reset
        $result = $this->limiter->check($key, $this->policy);
        $this->assertTrue($result->allowed);
        $this->assertEquals(4, $result->remaining);
    }

    public function testPeekDoesNotIncrement(): void
    {
        $key = 'test_ip_5';

        // Initial peek should show full quota (5 remaining)
        $this->assertEquals(5, $this->limiter->peek($key, $this->policy)->remaining);

        $this->limiter->check($key, $this->policy);
        $this->limiter->check($key, $this->policy);

        // Peek should not increment - still 3 remaining (5 - 2)
        $peekResult = $this->limiter->peek($key, $this->policy);
        $this->assertEquals(3, $peekResult->remaining);

        // Actual check should still show 3 remaining (since peek didn't increment)
        $checkResult = $this->limiter->check($key, $this->policy);
        $this->assertEquals(2, $checkResult->remaining);
    }

    public function testHeaders(): void
    {
        $key = 'test_ip_6';
        $result = $this->limiter->check($key, $this->policy);

        $headers = $result->toHeaders();
        $this->assertArrayHasKey('X-RateLimit-Limit', $headers);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $headers);
        $this->assertArrayHasKey('X-RateLimit-Reset', $headers);
        $this->assertArrayHasKey('Retry-After', $headers);
        $this->assertEquals('5', $headers['X-RateLimit-Limit']);
    }

    public function testTokenBucketAlgorithm(): void
    {
        $tokenBucketPolicy = RateLimitPolicy::fromArray('token_bucket', [
            'requests' => 10,
            'window' => 'minute', // 10 per minute = 10/60 per second
            'algorithm' => 'token_bucket',
            'key' => 'ip',
        ]);

        $key = 'test_tb_1';

        // Should allow up to 10 requests (burst)
        for ($i = 1; $i <= 10; $i++) {
            $result = $this->limiter->check($key, $tokenBucketPolicy);
            $this->assertTrue($result->allowed, "Burst request $i should be allowed");
        }

        // 11th should be denied
        $result = $this->limiter->check($key, $tokenBucketPolicy);
        $this->assertFalse($result->allowed);
    }

    public function testClearAll(): void
    {
        $key1 = 'test_clear_1';
        $key2 = 'test_clear_2';

        $this->limiter->check($key1, $this->policy);
        $this->limiter->check($key2, $this->policy);

        $this->limiter->clearAll();

        // Both should be reset - use peek to not increment
        $result = $this->limiter->peek($key1, $this->policy);
        $this->assertTrue($result->allowed);
        $this->assertEquals(5, $result->remaining);
    }

    public function testCustomWindowSeconds(): void
    {
        $customPolicy = RateLimitPolicy::fromArray('custom', [
            'requests' => 3,
            'window' => '30', // 30 seconds
            'algorithm' => 'sliding_window',
            'key' => 'ip',
        ]);

        $key = 'test_custom_window';
        for ($i = 1; $i <= 3; $i++) {
            $this->assertTrue($this->limiter->check($key, $customPolicy)->allowed);
        }
        $this->assertFalse($this->limiter->check($key, $customPolicy)->allowed);
    }
}