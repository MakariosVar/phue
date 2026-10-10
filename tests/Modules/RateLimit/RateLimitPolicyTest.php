<?php

declare(strict_types=1);

namespace Phue\Tests\Modules\RateLimit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Modules\RateLimit\Policy\RateLimitPolicy;

#[Group('unit')]
class RateLimitPolicyTest extends TestCase
{
    public function testCreateFromArray(): void
    {
        $policy = RateLimitPolicy::fromArray('test', [
            'requests' => 100,
            'window' => 'minute',
            'algorithm' => 'sliding_window',
            'key' => 'ip',
        ]);

        $this->assertEquals('test', $policy->name);
        $this->assertEquals(100, $policy->requests);
        $this->assertEquals('minute', $policy->window);
        $this->assertEquals('sliding_window', $policy->algorithm);
        $this->assertEquals('ip', $policy->keyResolver);
    }

    public function testDefaultValues(): void
    {
        $policy = RateLimitPolicy::fromArray('default', [
            'requests' => 60,
        ]);

        $this->assertEquals('minute', $policy->window);
        $this->assertEquals('sliding_window', $policy->algorithm);
        $this->assertEquals('ip', $policy->keyResolver);
        $this->assertNull($policy->scope);
        $this->assertEquals([], $policy->meta);
    }

    public function testWindowSeconds(): void
    {
        $this->assertEquals(1, RateLimitPolicy::fromArray('t', ['requests' => 1, 'window' => 'second'])->getWindowSeconds());
        $this->assertEquals(60, RateLimitPolicy::fromArray('t', ['requests' => 1, 'window' => 'minute'])->getWindowSeconds());
        $this->assertEquals(3600, RateLimitPolicy::fromArray('t', ['requests' => 1, 'window' => 'hour'])->getWindowSeconds());
        $this->assertEquals(86400, RateLimitPolicy::fromArray('t', ['requests' => 1, 'window' => 'day'])->getWindowSeconds());
        $this->assertEquals(90, RateLimitPolicy::fromArray('t', ['requests' => 1, 'window' => '90'])->getWindowSeconds());
    }

    public function testMerge(): void
    {
        $base = RateLimitPolicy::fromArray('base', [
            'requests' => 100,
            'window' => 'minute',
            'algorithm' => 'sliding_window',
            'key' => 'ip',
        ]);

        $override = RateLimitPolicy::fromArray('override', [
            'requests' => 50,
            'window' => 'hour',
            'algorithm' => 'token_bucket',
            'key' => 'user',
            'scope' => 'controller',
            'meta' => ['custom' => 'value'],
        ]);

        $merged = $base->merge($override);

        $this->assertEquals('override', $merged->name);
        $this->assertEquals(50, $merged->requests);
        $this->assertEquals('hour', $merged->window);
        $this->assertEquals('token_bucket', $merged->algorithm);
        $this->assertEquals('user', $merged->keyResolver);
        $this->assertEquals('controller', $merged->scope);
        $this->assertEquals(['custom' => 'value'], $merged->meta);
    }

    public function testMergePreservesBaseScopeWhenOverrideNull(): void
    {
        $base = RateLimitPolicy::fromArray('base', [
            'requests' => 100,
            'window' => 'minute',
            'scope' => 'global',
        ]);

        $override = RateLimitPolicy::fromArray('override', [
            'requests' => 50,
            'window' => 'hour',
            'scope' => null,
        ]);

        $merged = $base->merge($override);

        $this->assertEquals('global', $merged->scope);
    }

    public function testFromAttribute(): void
    {
        $attr = new \Phue\Modules\RateLimit\Attribute\RateLimit(
            requests: 200,
            window: 'hour',
            key: 'user',
            algorithm: 'token_bucket',
            scope: 'route',
            meta: ['foo' => 'bar']
        );

        $policy = RateLimitPolicy::fromAttribute($attr);

        $this->assertEquals('inline', $policy->name);
        $this->assertEquals(200, $policy->requests);
        $this->assertEquals('hour', $policy->window);
        $this->assertEquals('user', $policy->keyResolver);
        $this->assertEquals('token_bucket', $policy->algorithm);
        $this->assertEquals('route', $policy->scope);
        $this->assertEquals(['foo' => 'bar'], $policy->meta);
    }

    public function testFromAttributeWithPolicyReference(): void
    {
        $attr = new \Phue\Modules\RateLimit\Attribute\RateLimit(policy: 'api');

        $policy = RateLimitPolicy::fromAttribute($attr);

        $this->assertEquals('api', $policy->name);
        $this->assertEquals(100, $policy->requests); // default
        $this->assertEquals('minute', $policy->window); // default
    }

    public function testAttributeShorthandPolicyString(): void
    {
        $attr = new \Phue\Modules\RateLimit\Attribute\RateLimit('strict');

        $this->assertEquals('strict', $attr->policy);
        $this->assertNull($attr->requests);
    }

    public function testAttributeStaticFactories(): void
    {
        $policyAttr = \Phue\Modules\RateLimit\Attribute\RateLimit::policy('api');
        $this->assertEquals('api', $policyAttr->policy);

        $inlineAttr = \Phue\Modules\RateLimit\Attribute\RateLimit::inline(10, 'minute', 'user', 'sliding_window', 'route');
        $this->assertEquals(10, $inlineAttr->requests);
        $this->assertEquals('minute', $inlineAttr->window);
        $this->assertEquals('user', $inlineAttr->key);
        $this->assertEquals('sliding_window', $inlineAttr->algorithm);
        $this->assertEquals('route', $inlineAttr->scope);
    }
}