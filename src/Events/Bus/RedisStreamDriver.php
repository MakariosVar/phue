<?php

declare(strict_types=1);

namespace Phue\Events\Bus;

use Redis;
use RuntimeException;

/**
 * High-Throughput Redis Streams Event Bus Driver.
 *
 * Utilizes Redis Streams (XADD / XREADGROUP) for persistent, real-time event streaming
 * with At-Least-Once Delivery guarantees and consumer group support.
 *
 * @package Phue\Events\Bus
 */
final class RedisStreamDriver implements EventBusInterface
{
    /**
     * Initializes the Redis Stream driver.
     *
     * @param Redis $redis Connected Redis client instance.
     * @param string $consumerGroup Consumer group name for stream processing.
     * @param string $consumerName Unique consumer instance identifier.
     */
    public function __construct(
        private readonly Redis $redis,
        private readonly string $consumerGroup = 'phue_event_group',
        private readonly string $consumerName = 'phue_worker_1'
    ) {}

    /**
     * Appends an event payload to the Redis Stream channel using XADD.
     *
     * @param string $channel Target Redis stream key.
     * @param object|array<string, mixed> $event Event payload object or array.
     * @return void
     */
    public function publish(string $channel, object|array $event): void
    {
        $payload = is_object($event) ? get_object_vars($event) : $event;
        $encoded = json_encode($payload);

        if ($encoded === false) {
            throw new RuntimeException("Failed to JSON encode event payload for channel: {$channel}");
        }

        // Execute XADD stream command (* generates automatic entry ID)
        $this->redis->xAdd($channel, '*', ['payload' => $encoded]);
    }

    /**
     * Subscribes to a Redis Stream channel and reads incoming events using consumer groups.
     *
     * @param string $channel Target Redis stream key.
     * @param callable $handler Callback function processing each received event payload.
     * @return void
     */
    public function subscribe(string $channel, callable $handler): void
    {
        // Ensure Consumer Group exists for the target stream
        try {
            $this->redis->xGroup('CREATE', $channel, $this->consumerGroup, '0', true);
        } catch (\Throwable) {
            // Group already exists, ignore exception
        }

        // Read unread messages from consumer group stream using XREADGROUP
        $messages = $this->redis->xReadGroup(
            $this->consumerGroup,
            $this->consumerName,
            [$channel => '>'],
            10,
            1000
        );

        if (!is_array($messages) || empty($messages[$channel])) {
            return;
        }

        foreach ($messages[$channel] as $messageId => $data) {
            $rawPayload = $data['payload'] ?? '{}';
            /** @var array<string, mixed> $eventData */
            $eventData = json_decode((string) $rawPayload, true) ?? [];

            // Execute application handler
            $handler($eventData);

            // Acknowledge processed message ID
            $this->redis->xAck($channel, $this->consumerGroup, [$messageId]);
        }
    }
}