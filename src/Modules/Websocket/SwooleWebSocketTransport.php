<?php

declare(strict_types=1);

namespace Phue\Modules\Websocket;

use Phue\Core\Websocket\Connection;
use Phue\Core\Websocket\WebSocketTransport;
use Phue\Core\Request;
use Throwable;

/**
 * WebSocket for Swoole, and the only file here that knows what Swoole is.
 *
 * Swoole owns the connection lifecycle, which is the whole reason this transport exists. An
 * HTTP streaming response could not be made safe on this server: it holds a worker for as long
 * as the client is connected, and Swoole offers no way to notice from inside the running
 * coroutine that the tab closed — not through write(), isWritable(), exists() or
 * connection_aborted(), because a small chunk is absorbed by the kernel send buffer long before
 * the broken pipe surfaces. Four open tabs took the dev server down.
 *
 * A WebSocket connection is not subject to that. Swoole keeps a registry of them, fires a Close
 * event when one goes away, and offers a ping for confirming the far end is still there.
 *
 * @package Phue\Modules\Websocket
 */
final class SwooleWebSocketTransport implements WebSocketTransport
{
    /**
     * Connections by transport id.
     *
     * @var array<string, Connection>
     */
    private array $connections = [];

    /**
     * @var null|object
     */
    private ?object $server = null;

    /** @var callable(Connection, Request): void */
    private $onConnect;

    /** @var callable(Connection, string): void */
    private $onMessage;

    /** @var callable(Connection): void */
    private $onClose;

    public function __construct(
        private readonly int $queueLimit = 256,
    ) {
        $this->onConnect = static function (): void {
        };
        $this->onMessage = static function (): void {
        };
        $this->onClose = static function (): void {
        };
    }

    /**
     * @return bool
     */
    public function isAvailable(): bool
    {
        return class_exists(\Swoole\WebSocket\Server::class) || class_exists(\Swoole\Http\Server::class);
    }

    /**
     * @param callable(Connection, Request): void $onConnect
     * @param callable(Connection, string): void $onMessage
     * @param callable(Connection): void $onClose
     * @return void
     */
    public function listen(callable $onConnect, callable $onMessage, callable $onClose): void
    {
        $this->onConnect = $onConnect;
        $this->onMessage = $onMessage;
        $this->onClose = $onClose;
    }

    /**
     * @param object $server
     * @return void
     */
    public function attach(object $server): void
    {
        $this->server = $server;

        // The server must be told WebSocket is on before it starts; that is the Server module's
        // job, not ours, but attaching before start() would silently do nothing.
        $server->on('open', function ($request, $response): void {
            $this->handleOpen($request);
        });

        $server->on('message', function ($request, $frame): void {
            $connection = $this->find((string) ($request->fd ?? ''));

            if ($connection !== null && $frame !== null) {
                try {
                    ($this->onMessage)($connection, (string) $frame->data);
                } catch (Throwable $e) {
                    // A handler that throws must not take the server with it. Closing this one
                    // connection is honest: whatever it was doing has failed.
                    $this->close($connection, 1011, 'handler failed');
                }
            }
        });

        $server->on('close', function ($request): void {
            $connection = $this->find((string) ($request->fd ?? ''));

            if ($connection === null) {
                return;
            }

            unset($this->connections[$connection->id()]);
            $connection->close();

            ($this->onClose)($connection);
        });
    }

    /**
     * @param Connection $connection
     * @param string $payload
     * @return bool
     */
    public function send(Connection $connection, string $payload): bool
    {
        $server = $this->server;

        if ($server === null || $connection->isClosed()) {
            return false;
        }

        $fd = $connection->id();

        try {
            // push() returns false once the socket is gone, which is the one thing that is
            // reliably observable here and was not observable over a streaming HTTP response.
            return $server->exist((int) $fd) && $server->push((int) $fd, $payload);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param Connection $connection
     * @param int $code
     * @param string $reason
     * @return void
     */
    public function close(Connection $connection, int $code = 1000, string $reason = ''): void
    {
        $server = $this->server;

        unset($this->connections[$connection->id()]);
        $connection->close();

        if ($server === null) {
            return;
        }

        try {
            if ($server->exist((int) $connection->id())) {
                $server->close((int) $connection->id(), $code, $reason);
            }
        } catch (Throwable) {
            // Already gone. Nothing to do and nothing to report.
        }
    }

    /**
     * The connection for a transport id, if it is still open.
     *
     * @param string $id
     * @return null|Connection
     */
    public function find(string $id): ?Connection
    {
        return $this->connections[$id] ?? null;
    }

    /**
     * @param object $request Swoole's handshake request.
     * @return void
     */
    private function handleOpen(object $request): void
    {
        $id = (string) ($request->fd ?? '');

        if ($id === '') {
            return;
        }

        $connection = new Connection($id, null, $this->queueLimit);
        $this->connections[$id] = $connection;

        try {
            ($this->onConnect)($connection, $this->toPhueRequest($request));
        } catch (Throwable) {
            $this->close($connection, 1011, 'handshake failed');
        }
    }

    /**
     * Present Swoole's handshake as a framework request, so an authenticator written against
     * the HTTP side — a session, a bearer token — works on a WebSocket handshake unchanged.
     *
     * The handshake is an ordinary HTTP request with an Upgrade header, which is why the
     * credentials are already there and why reusing a session is possible at all.
     */
    private function toPhueRequest(object $request): Request
    {
        $headers = [];

        foreach ((array) ($request->header ?? []) as $name => $value) {
            $headers[(string) $name] = (string) $value;
        }

        $query = [];
        parse_str((string) ($request->server['query_string'] ?? ''), $query);

        return new Request(
            method: 'GET',
            uri: (string) ($request->server['request_uri'] ?? '/'),
            queryParams: is_array($query) ? $query : [],
            headers: $headers,
        );
    }
}