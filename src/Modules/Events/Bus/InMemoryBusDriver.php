<?php

declare(strict_types=1);

namespace Phue\Modules\Events\Bus;

/**
 * In-Memory Event Bus Driver for testing and local development.
 *
 * Stores listeners in memory and dispatches events synchronously.
 *
 * @package Phue\Modules\Events\Bus
 */
final class InMemoryBusDriver implements EventBusInterface
{
    /** @var array<string, array<int, callable>> Synchronous event listeners indexed by channel. */
    private array $listeners = [];

    /**
     * Publishes an event payload synchronously to in-memory listeners.
     *
     * @param string $channel Target channel identifier.
     * @param object|array<string, mixed> $event Event payload object or array.
     * @return void
     */
    public function publish(string $channel, object|array $event): void
    {
        $payload = is_object($event) ? get_object_vars($event) : $event;

        if (!isset($this->listeners[$channel])) {
            return;
        }

        foreach ($this->listeners[$channel] as $handler) {
            $handler($payload);
        }
    }

    /**
     * Registers a callback listener for a given channel.
     *
     * @param string $channel Target channel identifier.
     * @param callable $handler Callback function receiving the event payload.
     * @return void
     */
    public function subscribe(string $channel, callable $handler): void
    {
        $this->listeners[$channel][] = $handler;
    }
}
