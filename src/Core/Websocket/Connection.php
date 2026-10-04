<?php

declare(strict_types=1);

namespace Phue\Core\Websocket;

/**
 * One connected client.
 *
 * The interesting field is the pending count. A WebSocket connection is a long-lived buffer that
 * somebody else fills at a speed they choose: a phone on a train stops reading, the kernel send
 * buffer fills, and everything the server publishes queues against it in userland. Unbounded,
 * that is a slow client turning into an out-of-memory kill of the whole worker — and because the
 * worker serves every other connection too, one bad client takes the site with it.
 *
 * So the queue is counted, and past a ceiling messages are dropped rather than stored. Dropping
 * is recoverable through the cursor: the client reconnects with the last id it saw and is handed
 * what it missed, or told it missed too much. Out-of-memory is not recoverable by anything.
 *
 * @package Phue\Core\Websocket
 */
final class Connection
{
    /**
     * @param string $id Transport-assigned identifier.
     * @param null|string $identity Whatever the application decided this client is.
     * @param int $queueLimit How many unread messages this connection will hold.
     */
    public function __construct(
        private readonly string $id,
        private ?string $identity = null,
        private readonly int $queueLimit = 256,
    ) {
    }

    /**
     * @return string
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * @return null|string
     */
    public function identity(): ?string
    {
        return $this->identity;
    }

    /**
     * @param null|string $identity
     * @return void
     */
    public function identify(?string $identity): void
    {
        $this->identity = $identity;
    }

    /**
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        return $this->identity !== null && $this->identity !== '';
    }

    /**
     * The channels this connection is subscribed to.
     *
     * @var array<string, true>
     */
    private array $channels = [];

    /**
     * The highest cursor this connection has been sent, for its own reconnect.
     *
     * @var int
     */
    private int $lastSeen = 0;

    /**
     * @var int
     */
    private int $queued = 0;

    /**
     * @var int
     */
    private int $dropped = 0;

    /**
     * @var bool
     */
    private bool $closed = false;

    /**
     * @param string $channel
     * @return void
     */
    public function join(string $channel): void
    {
        $this->channels[$channel] = true;
    }

    /**
     * @param string $channel
     * @return void
     */
    public function leave(string $channel): void
    {
        unset($this->channels[$channel]);
    }

    /**
     * @param string $channel
     * @return bool
     */
    public function has(string $channel): bool
    {
        return isset($this->channels[$channel]);
    }

    /**
     * @return list<string>
     */
    public function channels(): array
    {
        return array_keys($this->channels);
    }

    /**
     * @return void
     */
    public function forgetChannels(): void
    {
        $this->channels = [];
    }

    /**
     * Record that a message reached this connection.
     *
     * @param int $id
     * @return void
     */
    public function saw(int $id): void
    {
        if ($id > $this->lastSeen) {
            $this->lastSeen = $id;
        }
    }

    /**
     * @return int
     */
    public function lastSeen(): int
    {
        return $this->lastSeen;
    }

    /**
     * Reserve room for one message.
     *
     * @return bool False when the queue is full, which means the caller must drop rather than
     *         buffer. One message over the limit is discarded, not the connection: dropping the
     *         connection punishes a slow reader as hard as an abandoned one.
     */
    public function reserve(): bool
    {
        if ($this->queued >= $this->queueLimit) {
            $this->dropped++;

            return false;
        }

        $this->queued++;

        return true;
    }

    /**
     * Give back a slot once a message has actually been written.
     *
     * @return void
     */
    public function release(): void
    {
        if ($this->queued > 0) {
            $this->queued--;
        }
    }

    /**
     * @return int
     */
    public function queued(): int
    {
        return $this->queued;
    }

    /**
     * @return int
     */
    public function dropped(): int
    {
        return $this->dropped;
    }

    /**
     * @return int
     */
    public function queueLimit(): int
    {
        return $this->queueLimit;
    }

    /**
     * @return void
     */
    public function close(): void
    {
        // Deliberately does not forget the channels. detach() finds them through the
        // connection in order to unregister it, so clearing them here first would leave the
        // registry holding a dead subscriber that every later publish still tries to write to.
        $this->closed = true;
    }

    /**
     * @return bool
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }
}