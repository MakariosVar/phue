<?php

declare(strict_types=1);

namespace Phue\Tests\Events;

use PHPUnit\Framework\TestCase;
use Phue\Events\Bus\RedisStreamDriver;
use Redis;
use RuntimeException;

/**
 * Integration tests for RedisStreamDriver against a real Redis server.
 *
 * Requires:
 *   - phpredis extension (docker-php-ext / pecl install redis)
 *   - Redis server reachable at PHUE_REDIS_HOST:PHUE_REDIS_PORT (default phue_redis:6379)
 *
 * These tests are skipped when Redis is not available so the core suite stays portable.
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class RedisStreamDriverTest extends TestCase
{
    private const STREAM_PREFIX = 'phue_test_';

    private ?Redis $redis;
    private string $streamName;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('redis')) {
            $this->markTestSkipped('phpredis extension not installed.');
        }

        $host = getenv('PHUE_REDIS_HOST') ?: 'phue_redis';
        $port = (int) (getenv('PHUE_REDIS_PORT') ?: 6379);

        $this->redis = new \Redis();
        try {
            $connected = @$this->redis->connect($host, $port, 1.0);
        } catch (\Throwable) {
            $connected = false;
        }

        if (!$connected) {
            $this->markTestSkipped("Redis server not reachable at {$host}:{$port}.");
        }

        $this->streamName = self::STREAM_PREFIX . uniqid();
    }

    protected function tearDown(): void
    {
        if ($this->redis) {
            try {
                $this->redis->del($this->streamName);
            } catch (\Throwable) {
                // best effort cleanup
            }
        }
        parent::tearDown();
    }

    private function createDriver(string $group = 'test_group', string $consumer = 'test_consumer'): RedisStreamDriver
    {
        return new RedisStreamDriver($this->redis, $group, $consumer);
    }

    public function testPublishAppendsToStream(): void
    {
        $driver = $this->createDriver();
        $driver->publish($this->streamName, ['order_id' => 42, 'status' => 'paid']);

        $entries = $this->redis->xRange($this->streamName, '-', '+');
        $this->assertCount(1, $entries);

        $payload = json_decode($entries[array_key_first($entries)]['payload'], true);
        $this->assertSame(42, $payload['order_id']);
        $this->assertSame('paid', $payload['status']);
    }

    public function testPublishObjectPayload(): void
    {
        $driver = $this->createDriver();

        $event = new \stdClass();
        $event->order_id = 99;
        $event->status = 'shipped';

        $driver->publish($this->streamName, $event);

        $entries = $this->redis->xRange($this->streamName, '-', '+');
        $this->assertCount(1, $entries);

        $payload = json_decode($entries[array_key_first($entries)]['payload'], true);
        $this->assertSame(99, $payload['order_id']);
        $this->assertSame('shipped', $payload['status']);
    }

    public function testPublishPreservesOrderOfMultipleEvents(): void
    {
        $driver = $this->createDriver();

        $driver->publish($this->streamName, ['seq' => 1]);
        $driver->publish($this->streamName, ['seq' => 2]);
        $driver->publish($this->streamName, ['seq' => 3]);

        $entries = $this->redis->xRange($this->streamName, '-', '+');
        $this->assertCount(3, $entries);

        $seqs = array_values(array_map(
            fn($e) => json_decode($e['payload'], true)['seq'],
            $entries
        ));
        $this->assertSame([1, 2, 3], $seqs);
    }

    public function testSubscribeReadsPendingMessageAndCallsHandler(): void
    {
        $driver = $this->createDriver();

        // Establish the consumer group first (deterministic: '>' only reads messages after group creation)
        $driver->subscribe($this->streamName, function (array $data) {});

        // Publish after the group exists, then read it back through the same group
        $driver->publish($this->streamName, ['order_id' => 101, 'status' => 'pending']);

        $received = null;
        $driver->subscribe($this->streamName, function (array $data) use (&$received) {
            $received = $data;
        });

        $this->assertNotNull($received);
        $this->assertSame(101, $received['order_id']);
        $this->assertSame('pending', $received['status']);
    }

    public function testSubscribeWithPublishAfterSubscribeDelivers(): void
    {
        $driver = $this->createDriver();

        // Create consumer group first by subscribing once (no messages yet)
        $driver->subscribe($this->streamName, function (array $data) {});

        // Now publish - should be delivered to existing consumer group
        $received = null;
        $driver->publish($this->streamName, ['order_id' => 200, 'status' => 'active']);

        // Subscribe again to consume from the group
        $driver->subscribe($this->streamName, function (array $data) use (&$received) {
            $received = $data;
        });

        $this->assertNotNull($received);
        $this->assertSame(200, $received['order_id']);
        $this->assertSame('active', $received['status']);
    }

    public function testSubscribeAcknowledgesMessages(): void
    {
        $driver = $this->createDriver();

        // Create group
        $driver->subscribe($this->streamName, function (array $data) {});

        // Publish a message
        $driver->publish($this->streamName, ['event' => 'test']);

        // Consume it
        $driver->subscribe($this->streamName, function (array $data) {});

        // After ack, no pending messages should remain for the group
        $pending = $this->redis->xPending($this->streamName, 'test_group');
        $this->assertIsArray($pending);
        $this->assertSame(0, (int) $pending[0]);
    }

    public function testSubscribeHandlesEmptyStreamGracefully(): void
    {
        $driver = $this->createDriver();

        $handlerCalled = false;
        $driver->subscribe($this->streamName, function (array $data) use (&$handlerCalled) {
            $handlerCalled = true;
        });

        $this->assertFalse($handlerCalled);
    }

    public function testGroupAlreadyExistsDoesNotThrow(): void
    {
        $driver = $this->createDriver('persistent_group');

        // First subscribe creates group, second will hit BUSYGROUP which must be tolerated
        $driver->subscribe($this->streamName, function (array $data) {});
        $driver->subscribe($this->streamName, function (array $data) {});

        // Group must still exist and remain usable after the BUSYGROUP collision
        $groups = $this->redis->xInfo('GROUPS', $this->streamName);
        $this->assertIsArray($groups);
        $this->assertSame('persistent_group', $groups[0]['name']);
    }

    public function testPublishDeliversExactEventCountToConsumerGroup(): void
    {
        $driver = $this->createDriver('counter_group', 'counter_consumer');

        // Establish the group
        $driver->subscribe($this->streamName, function (array $data) {});

        // Publish 5 events
        for ($i = 1; $i <= 5; $i++) {
            $driver->publish($this->streamName, ['seq' => $i]);
        }

        // Consume everything from the group
        $received = [];
        $driver->subscribe($this->streamName, function (array $data) use (&$received) {
            $received[] = $data['seq'];
        });

        // XREADGROUP '>' delivers each message to the group exactly once per read cycle.
        $this->assertCount(5, $received);

        // After ack, no pending messages remain.
        $pending = $this->redis->xPending($this->streamName, 'counter_group');
        $this->assertSame(0, (int) $pending[0]);
    }
}

