<?php

declare(strict_types=1);

namespace Phue\Tests\Websocket\Support;

use Phue\Core\Request;
use Phue\Core\Websocket\Connection;
use Phue\Core\Websocket\WebSocketTransport;

/**
 * A transport that records what it was told, so the module can be tested without a server.
 *
 * The alternative is a Swoole process in the test suite, which would make every assertion in
 * this file a question of timing and port availability. The interface exists precisely so this
 * double is possible.
 *
 * @package Phue\Tests\Websocket
 */
final class FakeTransport implements WebSocketTransport
{
    /** @var list<array{0: string, 1: string}> */
    public array $sent = [];

    /** @var list<array{0: Connection, 1: int, 2: string}> */
    public array $closed = [];

    /** @var array<string, bool> Connection ids whose send should fail. */
    public array $failing = [];

    /** @var null|object */
    public ?object $attached = null;

    /** @var null|callable */
    private $onConnect = null;

    /** @var null|callable */
    private $onMessage = null;

    /** @var null|callable */
    private $onClose = null;

    public function listen(callable $onConnect, callable $onMessage, callable $onClose): void
    {
        $this->onConnect = $onConnect;
        $this->onMessage = $onMessage;
        $this->onClose = $onClose;
    }

    public function attach(object $server): void
    {
        $this->attached = $server;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function send(Connection $connection, string $payload): bool
    {
        if (isset($this->failing[$connection->id()])) {
            return false;
        }

        $this->sent[] = [$connection->id(), $payload];

        return true;
    }

    public function close(Connection $connection, int $code = 1000, string $reason = ''): void
    {
        $this->closed[] = [$connection, $code, $reason];
    }

    // ------------------------------------------------------------- test-side triggers

    public function connect(Connection $connection, array $headers = [], array $query = []): void
    {
        ($this->onConnect)($connection, new Request(
            method: 'GET',
            uri: '/ws',
            queryParams: $query,
            headers: $headers,
        ));
    }

    public function message(Connection $connection, string $raw): void
    {
        ($this->onMessage)($connection, $raw);
    }

    public function disconnect(Connection $connection): void
    {
        $connection->close();
        ($this->onClose)($connection);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function decoded(): array
    {
        return array_map(
            static fn(array $pair): array => (array) json_decode($pair[1], true),
            $this->sent
        );
    }
}