<?php

declare(strict_types=1);

namespace Phue\Modules\Events\Bus;

/**
 * Interface contract for Phue Real-Time Event Bus Subsystem.
 *
 * Provides event publishing and subscription mechanics across distributed channels.
 *
 * @package Phue\Modules\Events\Bus
 */
interface EventBusInterface
{
    /**
     * Publishes an event payload to a specified channel.
     *
     * @param string $channel Target channel identifier.
     * @param object|array<string, mixed> $event Event payload object or associative array.
     * @return void
     */
    public function publish(string $channel, object|array $event): void;

    /**
     * Subscribes a callable handler to receive events from a specified channel.
     *
     * @param string $channel Target channel identifier.
     * @param callable $handler Callback function invoked when an event arrives: fn(array $eventData): void
     * @return void
     */
    public function subscribe(string $channel, callable $handler): void;
}
