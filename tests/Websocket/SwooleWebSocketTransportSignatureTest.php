<?php

declare(strict_types=1);

namespace Phue\Tests\Websocket;

use PHPUnit\Framework\TestCase;
use Phue\Core\Websocket\Connection;
use Phue\Modules\Websocket\SwooleWebSocketTransport;
use Phue\Core\Websocket\WebSocketTransport;

/**
 * Swoole passes the server as the first argument to every WebSocket event, and the connection
 * or frame second. Getting that backwards is invisible until it matters: the handshake still
 * returns 101, because Swoole completes the upgrade on its own, so a browser looks connected,
 * and then not a single frame ever arrives. Nothing in the logs says why.
 *
 * So the argument order is pinned here against a stand-in that behaves the way Swoole does.
 */
final class SwooleWebSocketTransportSignatureTest extends TestCase
{
    /**
     * A stand-in for Swoole's server that records the handlers registered against each event and
     * only ever calls them the way Swoole does.
     *
     * @var array<string, callable>
     */
    private array $handlers = [];

    /** @var list<array<int, mixed>> */
    private array $server = [];

    /** @var list<array<int, mixed>> */
    private array $frame = [];

    private function server(): object
    {
        return new class ($this->handlers, $this->server, $this->frame) {
            /**
             * @param array<string, callable> $handlers
             * @param list<array<int, mixed>> $seenServer
             * @param list<array<int, mixed>> $seenFrame
             */
            public function __construct(
                private array &$handlers,
                private array &$seenServer,
                private array &$seenFrame
            ) {
            }

            public function on(string $event, callable $handler): bool
            {
                $this->handlers[$event] = $handler;

                return true;
            }

            public function exist(int $fd): bool
            {
                return true;
            }

            public function push(int $fd, string $payload): bool
            {
                $this->seenFrame[] = [$fd, $payload];

                return true;
            }
        };
    }

    public function testASubscriptionArrivesWhenSwooleCallsMessageWithTheServerFirst(): void
    {
        $subscribed = null;
        $transport = $this->transport(
            onConnect: static function (Connection $c) use (&$subscribed): void {
                $subscribed = $c->id();
            },
            onMessage: static function (Connection $c, string $raw) use (&$subscribed): void {
                $subscribed .= '|' . $raw;
            }
        );

        $server = $this->server();
        $transport->attach($server);

        // Swoole's real order: server first, frame second.
        ($this->handlers['open'])($server, $this->handshakeRequest('7'));
        $this->assertSame('7', $subscribed, 'the handshake should register the connection');

        ($this->handlers['message'])($server, $this->frame(7, '{"act":"sub","ch":"x"}'));

        $this->assertSame('7|{"act":"sub","ch":"x"}', $subscribed);
    }

    /**
     * The regression that mattered: with the arguments the other way round the handler saw a
     * server where it expected a request, found no file descriptor, and silently ignored every
     * frame the client sent.
     */
    public function testTheMessageHandlerReadsTheFileDescriptorFromTheFrame(): void
    {
        $seen = [];
        $transport = $this->transport(
            onConnect: static fn (Connection $c): null => null,
            onMessage: static function (Connection $c) use (&$seen): void {
                $seen[] = $c->id();
            }
        );

        $server = $this->server();
        $transport->attach($server);

        ($this->handlers['open'])($server, $this->handshakeRequest('12'));
        ($this->handlers['message'])($server, $this->frame(12, '{"act":"sub","ch":"x"}'));

        $this->assertSame(['12'], $seen, 'the frame carries the descriptor, not the server');
    }

    public function testAClosedConnectionIsForgotten(): void
    {
        $transport = $this->transport(
            onConnect: static fn (Connection $c): null => null,
            onMessage: static fn (Connection $c, string $r): null => null
        );

        $server = $this->server();
        $transport->attach($server);

        ($this->handlers['open'])($server, $this->handshakeRequest('3'));
        $this->assertNotNull($transport->find('3'));

        // Swoole's close signature is the server and the descriptor. Reading it as a request
        // leaves a connection in the table that no longer exists, which then fails to send.
        ($this->handlers['close'])($server, 3);

        $this->assertNull($transport->find('3'), 'a closed socket must not stay registered');
    }

    public function testAFrameForAnUnknownConnectionIsIgnored(): void
    {
        $seen = [];
        $transport = $this->transport(
            onConnect: static fn (Connection $c): null => null,
            onMessage: static function (Connection $c) use (&$seen): void {
                $seen[] = $c->id();
            }
        );

        $server = $this->server();
        $transport->attach($server);

        // A frame can arrive after the close event has already been processed.
        ($this->handlers['message'])($server, $this->frame(99, '{"act":"sub","ch":"x"}'));

        $this->assertSame([], $seen);
    }

    private function transport(callable $onConnect, callable $onMessage): SwooleWebSocketTransport
    {
        $transport = new SwooleWebSocketTransport(queueLimit: 16);
        $transport->listen($onConnect, $onMessage, static fn (Connection $c): null => null);

        return $transport;
    }

    /**
     * Stands in for Swoole's handshake request, with the public properties it really has.
     */
    private function handshakeRequest(string $fd): object
    {
        return new class ($fd) {
            public string $fd;

            /** @var array<string, string> */
            public array $header = [];

            /** @var array<string, mixed> */
            public array $server = ['request_uri' => '/ws', 'query_string' => ''];

            public function __construct(string $fd)
            {
                $this->fd = $fd;
            }
        };
    }

    private function frame(int $fd, string $data): object
    {
        return new class ($fd, $data) {
            public function __construct(public int $fd, public string $data)
            {
            }
        };
    }

    public function testItIsUsableAsATransport(): void
    {
        $this->assertInstanceOf(WebSocketTransport::class, $this->transport(
            static fn (Connection $c): null => null,
            static fn (Connection $c, string $r): null => null
        ));
    }
}
