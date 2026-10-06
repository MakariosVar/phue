<?php

declare(strict_types=1);

namespace Phue\Tests\Support;

use RuntimeException;

/**
 * A WebSocket client, written here rather than depended on.
 *
 * The end-to-end test has to hold real sockets open against a real server, and nothing in PHP can
 * do that: ext-redis speaks Redis and cURL speaks HTTP/1.1, and neither of them will complete a
 * handshake or read a frame. A dependency that could would be a second WebSocket implementation to
 * trust, and the point of this file is that the thing under test is the *server* — RFC 6455 in, frame
 * out, and nothing clever enough to be wrong in an interesting way.
 *
 * Only what the tests use: a text-frame client that sends masked frames (the specification requires
 * it from a client and a server that does not expect it closes the connection), reads unmasked ones,
 * answers a ping, and can be told which worker the server says it is sitting on.
 *
 * @internal Used by the end-to-end WebSocket tests.
 */
final class WebSocketClient
{
    /** @var resource|null */
    private $stream = null;

    /** Bytes read from the socket that do not yet make a whole frame. */
    private string $buffer = '';

    /**
     * Frames a caller has read past, in order.
     *
     * @var list<array{op: int, payload: string}>
     */
    private array $backlog = [];

    /**
     * Put frames back, so a caller that read past them can still see them.
     *
     * The protocol puts data before the acknowledgement that confirms it: a subscriber asking for what
     * it missed is sent the replay first and the `phue.subscribed` afterwards, so any test that waits
     * for the acknowledgement and then looks for the data has to have kept what it walked over. Which
     * is not a thing a test should have to know about the order to assert anything.
     *
     * @param list<array{op: int, payload: string}> $frames
     */
    public function pushback(array $frames): void
    {
        array_splice($this->backlog, 0, 0, $frames);
    }

    /**
     * @param resource $stream
     */
    private function __construct($stream)
    {
        $this->stream = $stream;
    }

    /**
     * Complete a handshake and return a connected client.
     *
     * @param array<string, string> $headers Sent verbatim, which is how a test sets Origin.
     *
     * @throws RuntimeException When the server refuses the upgrade, which is the answer every test
     *         that asserts a refusal is looking for.
     */
    public static function connect(string $host, int $port, string $path, array $headers = []): self
    {
        $errno = 0;
        $errstr = '';

        $stream = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $errstr,
            5.0,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($stream)) {
            throw new RuntimeException(sprintf('Could not connect to %s:%d — %s (%d).', $host, $port, $errstr, $errno));
        }

        stream_set_timeout($stream, 5);

        $lines = [
            sprintf('GET %s HTTP/1.1', $path),
            sprintf('Host: %s:%d', $host, $port),
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16)),
            'Sec-WebSocket-Version: 13',
        ];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        fwrite($stream, implode("\r\n", $lines) . "\r\n\r\n");

        $client = new self($stream);
        $response = $client->readUntil("\r\n\r\n", 5.0);

        if (!str_starts_with($response, 'HTTP/1.1 101')) {
            $status = strtok($response, "\r\n");

            throw new RuntimeException(sprintf('The upgrade was refused: %s', $status === false ? '(no response)' : $status));
        }

        return $client;
    }

    /**
     * Send one text frame.
     *
     * @param array<string, mixed> $payload
     */
    public function send(array $payload): void
    {
        $this->writeFrame(1, (string) json_encode($payload));
    }

    /**
     * The next frame, or null when none arrived before the deadline.
     *
     * A ping is answered and skipped rather than returned, because a client that hands ping frames
     * to the caller makes every message assertion in the suite a little more careful than it should
     * be for a frame nobody sent on purpose.
     *
     * @return array{op: int, payload: string}|null
     */
    public function receive(float $timeout = 5.0): ?array
    {
        if ($this->backlog !== []) {
            return array_shift($this->backlog);
        }

        $deadline = microtime(true) + $timeout;

        while (true) {
            $frame = $this->frameFromBuffer();

            if ($frame !== null) {
                if ($frame['op'] === 0x9) {
                    $this->writeFrame(0xA, $frame['payload']);

                    continue;
                }

                return $frame;
            }

            if (microtime(true) >= $deadline) {
                return null;
            }

            $chunk = @fread($this->socket(), 4096);

            if ($chunk === false || $chunk === '') {
                // An empty read is either the deadline or a closed socket; the loop condition decides,
                // which keeps a slow frame from being mistaken for a dead connection.
                $meta = stream_get_meta_data($this->socket());

                if (($meta['timed_out'] ?? false) === false && ($meta['eof'] ?? false) === true) {
                    throw new RuntimeException('The server closed the connection.');
                }

                continue;
            }

            $this->buffer .= $chunk;
        }
    }

    /**
     * Every frame until one satisfies $matches, or the deadline passes.
     *
     * @param callable(array{op: int, payload: string}): bool $matches
     * @return array{op: int, payload: string}|null
     */
    public function await(callable $matches, float $timeout = 5.0): ?array
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $frame = $this->receive(max(0.05, $deadline - microtime(true)));

            if ($frame === null) {
                continue;
            }

            if ($matches($frame)) {
                return $frame;
            }
        }

        return null;
    }

    /**
     * Close the socket, and do not complain if it is already gone.
     */
    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }

        $this->stream = null;
    }

    /**
     * The socket, or a complaint rather than a TypeError from deep inside a read.
     *
     * A resource is a resource until it is closed, and PHP 8 has no way to say so in a property type
     * — so the check lives where the cast used to be, which is the one place that can be wrong.
     *
     * @return resource
     */
    private function socket()
    {
        if (!is_resource($this->stream)) {
            throw new RuntimeException('This client is closed.');
        }

        return $this->stream;
    }

    /**
     * @param int $opcode
     */
    private function writeFrame(int $opcode, string $payload): void
    {
        $length = strlen($payload);

        $frame = chr(0x80 | $opcode);

        if ($length < 126) {
            $frame .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $length);
        }

        // Masked, because the specification says a client must mask and a server that does not expect
        // it treats the payload as corrupt. This is the one part of RFC 6455 that is not optional.
        $mask = random_bytes(4);
        $frame .= $mask;

        $masked = '';

        for ($i = 0; $i < $length; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        fwrite($this->socket(), $frame . $masked);
    }

    /**
     * A whole frame out of the buffer, or null while the bytes for one are still missing.
     *
     * @return array{op: int, payload: string}|null
     */
    private function frameFromBuffer(): ?array
    {
        if (strlen($this->buffer) < 2) {
            return null;
        }

        // Read by hand rather than with unpack(): the named format for two adjacent bytes is a trap
        // waiting for the next reader, and the whole frame header is two bitfields anyway.
        $first = ord($this->buffer[0]);
        $second = ord($this->buffer[1]);

        $opcode = $first & 0x0F;
        $masked = ($second & 0x80) === 0x80;
        $length = $second & 0x7F;
        $offset = 2;

        if ($length === 126) {
            if (strlen($this->buffer) < 4) {
                return null;
            }

            $extended = unpack('n', substr($this->buffer, 2, 2));
            $length = (int) ($extended[1] ?? 0);
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($this->buffer) < 10) {
                return null;
            }

            $extended = unpack('J', substr($this->buffer, 2, 8));
            $length = (int) ($extended[1] ?? 0);
            $offset = 10;
        }

        $maskKey = '';

        if ($masked) {
            if (strlen($this->buffer) < $offset + 4) {
                return null;
            }

            $maskKey = substr($this->buffer, $offset, 4);
            $offset += 4;
        }

        if (strlen($this->buffer) < $offset + $length) {
            return null;
        }

        $payload = substr($this->buffer, $offset, $length);
        $this->buffer = substr($this->buffer, $offset + $length);

        if ($masked) {
            $unmasked = '';

            for ($i = 0; $i < $length; $i++) {
                $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
            }

            $payload = $unmasked;
        }

        return ['op' => $opcode, 'payload' => $payload];
    }

    /**
     * Read until $delimiter appears, for the handshake.
     */
    private function readUntil(string $delimiter, float $timeout): string
    {
        $deadline = microtime(true) + $timeout;

        while (!str_contains($this->buffer, $delimiter)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The server never finished the handshake.');
            }

            $chunk = @fread($this->socket(), 1024);

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket());

                if (($meta['timed_out'] ?? false) === false) {
                    throw new RuntimeException('The server closed the connection during the handshake.');
                }

                continue;
            }

            $this->buffer .= $chunk;
        }

        [$headers] = explode($delimiter, $this->buffer, 2);
        $this->buffer = substr($this->buffer, strlen($headers) + strlen($delimiter));

        return $headers;
    }
}