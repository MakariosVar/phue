<?php

declare(strict_types=1);

namespace Phue\Core\Websocket;

/**
 * What a WebSocket server has to be able to do, with no opinion about which one it is.
 *
 * This exists so Core can hold the topic logic without importing a server extension. The same
 * registry runs unchanged behind Swoole, behind a test double, and behind whatever server the
 * application reaches for next — which is the same layering lesson the framework learned the
 * hard way when an SSE stream had to be taught to two different response emitters.
 *
 * @package Phue\Core\Websocket
 */
interface WebSocketTransport
{
    /**
     * Register the connection handlers.
     *
     * @param callable(Connection): void $onConnect
     * @param callable(Connection, string): void $onMessage
     * @param callable(Connection): void $onClose
     * @return void
     */
    public function listen(
        callable $onConnect,
        callable $onMessage,
        callable $onClose
    ): void;

    /**
     * Send an already-encoded payload to one connection.
     *
     * @param Connection $connection
     * @param string $payload
     * @return bool False when the message could not be handed to the socket. The caller keeps
     *         the slot occupied and does not advance the cursor, so a reconnecting client can
     *         still be given what it missed.
     */
    public function send(Connection $connection, string $payload): bool;

    /**
     * Close one connection.
     *
     * @param Connection $connection
     * @param int $code WebSocket close code.
     * @param string $reason
     * @return void
     */
    public function close(Connection $connection, int $code = 1000, string $reason = ''): void;

    /**
     * Whether this transport is usable in the current process.
     *
     * The answer decides whether the module registers its endpoint at all, rather than
     * registering something that 404s or hangs.
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * Attach to a running server.
     *
     * @param object $server The server this transport should hook into.
     * @return void
     */
    public function attach(object $server): void;
}