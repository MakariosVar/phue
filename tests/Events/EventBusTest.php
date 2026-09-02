<?php

declare(strict_types=1);

namespace Phue\Tests\Events;

use PHPUnit\Framework\TestCase;
use Phue\Events\Attribute\Broadcastable;
use Phue\Events\Bus\InMemoryBusDriver;
use Phue\Events\Sse\SseResponse;
use Phue\Events\Sse\SseStreamer;

#[Broadcastable(channel: 'test_orders')]
final class DummyBroadcastModel {}

/**
 * Unit tests for Event Bus drivers, SSE streamer/response, and Broadcastable attributes.
 *
 * @package Phue\Tests\Events
 */
final class EventBusTest extends TestCase
{
    /**
     * Tests publishing and subscribing using the InMemoryBusDriver.
     *
     * @return void
     */
    public function testInMemoryBusPublishAndSubscribe(): void
    {
        $bus = new InMemoryBusDriver();
        $receivedData = null;

        $bus->subscribe('orders', function (array $payload) use (&$receivedData) {
            $receivedData = $payload;
        });

        $bus->publish('orders', ['order_id' => 101, 'status' => 'paid']);

        $this->assertIsArray($receivedData);
        $this->assertSame(101, $receivedData['order_id']);
        $this->assertSame('paid', $receivedData['status']);
    }

    /**
     * Tests formatting of SSE stream blocks using SseStreamer.
     *
     * @return void
     */
    public function testSseStreamerFormatting(): void
    {
        $streamer = new SseStreamer();
        $formatted = $streamer->formatEvent('order.created', ['id' => 42], '1001');

        $expected = "id: 1001\nevent: order.created\ndata: {\"id\":42}\n\n";
        $this->assertSame($expected, $formatted);
    }

    /**
     * Tests SseResponse status code and mandatory streaming headers.
     *
     * @return void
     */
    public function testSseResponseHeaders(): void
    {
        $response = new SseResponse('data: hello');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/event-stream', $response->getHeaders()['Content-Type']);
        $this->assertSame('no-cache', $response->getHeaders()['Cache-Control']);
        $this->assertSame('keep-alive', $response->getHeaders()['Connection']);
        $this->assertSame('no', $response->getHeaders()['X-Accel-Buffering']);
    }

    /**
     * Tests Reflection extraction of Broadcastable attribute from annotated models.
     *
     * @return void
     */
    public function testBroadcastableAttributeReflection(): void
    {
        $reflector = new \ReflectionClass(DummyBroadcastModel::class);
        $attributes = $reflector->getAttributes(Broadcastable::class);

        $this->assertNotEmpty($attributes);
        
        /** @var Broadcastable $instance */
        $instance = $attributes[0]->newInstance();
        $this->assertSame('test_orders', $instance->channel);
        $this->assertTrue($instance->broadcastCreate);
    }
}