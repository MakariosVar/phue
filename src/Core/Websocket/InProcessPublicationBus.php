<?php

declare(strict_types=1);

namespace Phue\Core\Websocket;

/**
 * The bus for a process that is the only process: correct and free with one worker.
 *
 * Not a degraded mode — the cursor is monotonic, history is bounded, resume works. What it cannot
 * do is reach a process that does not exist, so a second worker receives nothing; {@see isShared()}
 * exists so the wiring can tell that case apart instead of letting it look like a lost subscriber.
 *
 * @package Phue\Core\Websocket
 */
final class InProcessPublicationBus implements PublicationBus
{
    /** @var array<string, array<int, string>> Retained payloads per channel, oldest first. */
    private array $history = [];

    /** @var array<string, int> Retention limit per channel. */
    private array $retention = [];

    public function __construct(
        private readonly string $origin = 'local',
        private readonly int $defaultRetention = 100
    ) {
    }

    /**
     * Whether messages published in one process reach the subscribers of another.
     *
     * @return bool False on a single-worker deployment, where there is no other process to reach.
     */
    public function isShared(): bool
    {
        return false;
    }

    /**
     * Assign a cursor, record the message, and announce it.
     *
     * @param string $channel Topic the message belongs to.
     * @param string $event Event name, conventionally `noun.verb`.
     * @param mixed $data Payload. Must survive a JSON round trip.
     * Nothing is retained for a reader. The publisher is the only process here, and it delivers to
     * its own subscribers before this returns, so a record kept for a second reader would be kept
     * for a reader that never comes: {@see next()} is only ever called on a shared bus.
     *
     * @param list<string> $except Connection ids to skip. Applied by the caller against its own
     *        subscribers, since those ids mean nothing outside this process.
     * @return array{id: int, payload: string} The cursor and the envelope carrying it.
     */
    public function publish(string $channel, string $event, mixed $data, array $except = []): array
    {
        $id = ++$this->cursorValue;

        $payload = Envelope::encode(Envelope::make($channel, $event, $data, $id));

        $this->remember($channel, $id, $payload);

        return ['id' => $id, 'payload' => $payload];
    }

    /**
     * Always null, because there is never another process here.
     *
     * `PublicationBus::next()` means "a message published elsewhere, for me to deliver", and in a
     * single-process deployment nothing is published elsewhere. Returning null is the honest answer;
     * returning the publisher's own message would deliver every broadcast twice to the very
     * subscribers it had just been sent to.
     *
     * @return null
     */
    public function next(): ?array
    {
        return null;
    }

    /**
     * Messages a client missed on one channel, oldest first.
     *
     * @param string $channel Topic to catch up on.
     * @param int $since The last cursor that client saw.
     * @return iterable<array{id: int, payload: string}>
     */
    public function missed(string $channel, int $since): iterable
    {
        foreach ($this->history[$channel] ?? [] as $id => $payload) {
            if ($id > $since) {
                yield ['id' => $id, 'payload' => $payload];
            }
        }
    }

    /**
     * The oldest cursor still retained on a channel.
     *
     * @param string $channel
     * @return int|null Null when nothing is retained, so a resume is never told it fell behind
     *         when there was nothing to fall behind.
     */
    public function oldest(string $channel): ?int
    {
        $ids = array_keys($this->history[$channel] ?? []);

        return $ids === [] ? null : (int) $ids[0];
    }

    /**
     * The most recently assigned cursor.
     *
     * @return int|null Null before anything has been published.
     */
    public function cursor(): ?int
    {
        return $this->cursorValue === 0 ? null : $this->cursorValue;
    }

    /**
     * Keep this many messages on a channel.
     *
     * @param string $channel
     * @param int $messages Zero keeps nothing, which is the right setting for a topic nobody
     *        reconnects to.
     * @return void
     */
    public function retain(string $channel, int $messages): void
    {
        $this->retention[$channel] = max(0, $messages);

        if ($messages === 0) {
            unset($this->history[$channel]);
        }
    }

    /**
     * A value identifying this process, recorded on every message it publishes.
     *
     * @return string
     */
    public function origin(): string
    {
        return $this->origin;
    }

    /** Highest cursor assigned so far; 0 means nothing has been published. */
    private int $cursorValue = 0;

    private function remember(string $channel, int $id, string $payload): void
    {
        $limit = $this->retention[$channel] ?? $this->defaultRetention;

        if ($limit === 0) {
            return;
        }

        $this->history[$channel][$id] = $payload;

        // Unset by key, never array_shift(): shifting an integer-keyed array renumbers the keys
        // from zero, which would turn cursor ids into sequence positions. A client resuming from
        // cursor 7 would then be handed whatever happened to be at index 8 — and a client that had
        // missed nothing would be told it had missed a great deal.
        while (count($this->history[$channel]) > $limit) {
            $oldest = array_key_first($this->history[$channel]);

            if ($oldest === null) {
                break;
            }

            unset($this->history[$channel][$oldest]);
        }
    }
}