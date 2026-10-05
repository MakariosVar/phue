<?php

declare(strict_types=1);

namespace Phue\Core\Websocket;

/**
 * The bus for more than one process, carried by a Redis stream.
 *
 * A stream rather than pub/sub because a *consumer group* distributes — each member gets a share,
 * and three quarters of the clients would receive nothing. Reading without a group is the opposite
 * and is what fan-out needs: each worker keeps its own offset and therefore sees every message.
 * That also brings the ordering and the replay window a resumable protocol requires.
 *
 * The cursor is an INCR counter, not the stream id: the envelope carries the cursor, so it has to
 * be built before the XADD that mints an id. Two round trips rather than one Lua script, because
 * atomicity buys nothing here — a failed write leaves a gap in the sequence, and cursors need to be
 * monotonic rather than gapless — while it avoids rewriting an encoded envelope to patch an id into
 * it, which is a string search through a document that may contain the same bytes in its own data.
 *
 * @package Phue\Core\Websocket
 */
final class RedisStreamPublicationBus implements SettablePublicationBus
{
    /** @var array<string, int> Retention per channel, sent with each XADD. */
    private array $retention = [];

    /** Read offset into the stream, held in memory and starting at "only new". */
    private string $offset = '$';

    /**
     * Takes over once Redis stops answering, for the rest of this process's life.
     *
     * @var InProcessPublicationBus|null
     */
    private ?InProcessPublicationBus $fallback = null;

    /** Whether the fall-over has already been reported, so one outage is one log line. */
    private bool $reported = false;

    /**
     * @param string $initialOffset Where this process starts reading. The default, `$`, means only
     *        messages published from now on — right for a worker that has just started and holds no
     *        connections yet, because replaying history to nobody is work for no reason. `0-0` is
     *        for a worker that was already running.
     * @param null\callable(\Throwable): void $onUnreachable Told once when Redis stops answering and
     *        this process takes over alone. A callable rather than a logger, because Core stays free
     *        of a logging dependency and a test can count the calls.
     */
    public function __construct(
        private readonly \Redis $redis,
        private readonly string $namespace = 'phue:ws',
        private readonly string $origin = '',
        private readonly int $defaultRetention = 100,
        private readonly string $initialOffset = '$',
        private $onUnreachable = null
    ) {
        $this->offset = $initialOffset;
    }

    /**
     * Whether messages published in one process reach the subscribers of another.
     *
     * @return bool False on a single-worker deployment, where there is no other process to reach.
     */
    public function isShared(): bool
    {
        return true;
    }

    /**
     * Identifies this process on every message it publishes.
     *
     * Unique per process, so the publishing worker can recognise the copy that comes back around
     * the stream and skip it. Otherwise every message arrives twice on a single-worker deployment
     * — the deployment nobody tests.
     */
    public function origin(): string
    {
        return $this->origin !== '' ? $this->origin : (string) getmypid();
    }

    /**
     * Assign a cursor, record the message, and announce it.
     *
     * @param string $channel Topic the message belongs to.
     * @param string $event Event name, conventionally `noun.verb`.
     * @param mixed $data Payload. Must survive a JSON round trip.
     * @param list<string> $except Connection ids to skip. Kept on the record rather than applied
     *        here, because those ids mean nothing in another process.
     * @return array{id: int, payload: string} The cursor and the envelope carrying it.
     */
    public function publish(string $channel, string $event, mixed $data, array $except = []): array
    {
        if ($this->fallback !== null) {
            return $this->fallback->publish($channel, $event, $data, $except);
        }

        try {
            return $this->publishToStream($channel, $event, $data, $except);
        } catch (\RedisException | \RuntimeException $e) {
            $this->fallBack($e);

            return $this->fallback->publish($channel, $event, $data, $except);
        }
    }

    /**
     * The stream write itself, separated so a failure can fall over and retry in local terms.
     *
     * @param string $channel Topic the message belongs to.
     * @param string $event Event name, conventionally `noun.verb`.
     * @param mixed $data Payload. Must survive a JSON round trip.
     * @param list<string> $except Connection ids to skip. Carried on the record rather than applied
     *        here, because those ids mean nothing in another process.
     * @return array{id: int, payload: string} The cursor and the envelope carrying it.
     */
    private function publishToStream(string $channel, string $event, mixed $data, array $except): array
    {
        $cursor = $this->redis->incr($this->cursorKey());

        if (!is_int($cursor)) {
            throw new \RuntimeException('Redis did not return an integer cursor for a published message.');
        }

        $payload = Envelope::encode(Envelope::make($channel, $event, $data, $cursor));

        // MAXLEN is approximate on purpose: exact trimming costs a scan Redis would otherwise skip,
        // and a few messages either side of a replay window changes nothing — a client outside it
        // is told it has a gap either way.
        $this->redis->xAdd(
            $this->streamKey(),
            '*',
            [
                'id' => (string) $cursor,
                'p' => $payload,
                'ch' => $channel,
                'x' => json_encode(array_values($except)) ?: '[]',
                'o' => $this->origin(),
            ],
            // One stream holds every channel, so MAXLEN bounds the stream rather than any one
            // channel. That is deliberate: it is the only bound Redis can enforce, and a busy
            // channel cannot be allowed to grow the stream without limit because a quiet one was
            // configured to keep nothing.
            //
            // Per-channel retention is therefore applied on read, by missed() and oldest(), and a
            // stream longer than the largest configured window is trimmed here.
            $this->streamLimit(),
            true
        );

        return ['id' => $cursor, 'payload' => $payload];
    }

    /**
     * The next message from another process, or null when there is none.
     *
     * @param int $blockMs Zero polls; a positive value makes this a push read, which is how
     *        delivery stays near-instant instead of costing a poll interval.
     */
    public function next(int $blockMs = 0): ?array
    {
        if ($this->fallback !== null) {
            return $this->fallback->next();
        }

        try {
            return $this->readFromStream($blockMs);
        } catch (\RedisException | \RuntimeException $e) {
            $this->fallBack($e);

            return null;
        }
    }

    /**
     * The stream read itself, separated so a failure can fall over without pretending to have read
     * anything.
     *
     * @param int $blockMs Milliseconds to wait for a message; zero polls.
     * @return array{id: int, channel: string, payload: string, origin: string, except: list<string>}|null
     */
    private function readFromStream(int $blockMs): ?array
    {
        // -1, not 0. phpredis uses it as "omit BLOCK from the command", which returns at once; a zero is
        // sent as `BLOCK 0`, which to Redis means "wait forever" — the opposite of what a zero here
        // asks for, and it surfaces as a connection read error rather than as an answer.
        $rows = $this->redis->xRead([$this->streamKey() => $this->offset], 1, $blockMs > 0 ? $blockMs : -1);

        if (!is_array($rows)) {
            return null;
        }

        foreach ($rows as $entries) {
            foreach ($entries as $streamId => $fields) {
                $this->offset = (string) $streamId;

                $fields = array_map(static fn($value): string => (string) $value, $fields);
                $cursor = (int) ($fields['id'] ?? 0);

                return [
                    'id' => $cursor,
                    'channel' => $fields['ch'] ?? '',
                    'payload' => $fields['p'] ?? '{}',
                    'origin' => $fields['o'] ?? '',
                    'except' => json_decode($fields['x'] ?? '[]', true) ?: [],
                ];
            }
        }

        return null;
    }

    /**
     * Messages a client missed on one channel, oldest first.
     *
     * @param string $channel Topic to catch up on.
     * @param int $since The last cursor that client saw.
     * @return iterable<array{id: int, payload: string}>
     */
    /**
     * Move the read offset to the end of the stream, discarding what is already there.
     *
     * Called once when a worker starts. A worker beginning now holds no connections, so anything
     * published before it existed has nobody to reach — reading it would only be work, and with an
     * `$` offset that never advances the worker would re-ask "anything new?" forever and never
     * see a message published between two of its polls.
     *
     * @return int How many entries were skipped, for a diagnostic rather than for control flow.
     */
    public function settle(): int
    {
        if ($this->fallback !== null || $this->offset !== '$') {
            return 0;
        }

        try {
            $entries = $this->retained();
            $this->offset = $this->lastStreamId();
        } catch (\RedisException | \RuntimeException $e) {
            $this->fallBack($e);

            return 0;
        }

        return count($entries);
    }

    /**
     * The stream id of the newest retained entry, for placing the offset past all of them.
     *
     * `xrevrange` and not the `…N` variants: they are not in every phpredis build, and a worker
     * that fatals at boot over a convenience argument costs a great deal more than the few bytes
     * reading one entry back saves.
     *
     * @return string
     */
    private function lastStreamId(): string
    {
        $rows = $this->redis->xRevRange($this->streamKey(), '+', '-', 1);

        if (!is_array($rows) || $rows === []) {
            return '0-0';
        }

        return (string) array_key_first($rows);
    }

    public function missed(string $channel, int $since): iterable
    {
        if ($this->fallback !== null) {
            return $this->fallback->missed($channel, $since);
        }

        try {
            $replayable = $this->replayable($channel);
        } catch (\RedisException | \RuntimeException $e) {
            $this->fallBack($e);

            return $this->fallback->missed($channel, $since);
        }

        foreach ($replayable as $cursor => $payload) {
            if ($cursor <= $since) {
                continue;
            }

            yield ['id' => $cursor, 'payload' => $payload];
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
        if ($this->fallback !== null) {
            return $this->fallback->oldest($channel);
        }

        try {
            $replayable = $this->replayable($channel);
        } catch (\RedisException | \RuntimeException $e) {
            $this->fallBack($e);

            return $this->fallback->oldest($channel);
        }

        if ($replayable === []) {
            return null;
        }

        return (int) array_key_first($replayable);
    }

    /**
     * The most recently assigned cursor.
     *
     * @return int|null Null before anything has been published.
     */
    public function cursor(): ?int
    {
        if ($this->fallback !== null) {
            return $this->fallback->cursor();
        }

        try {
            $value = $this->redis->get($this->cursorKey());
        } catch (\RedisException | \RuntimeException $e) {
            $this->fallBack($e);

            return $this->fallback->cursor();
        }

        return is_int($value) || (is_string($value) && $value !== '') ? (int) $value : null;
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

        if ($this->fallback !== null) {
            $this->fallback->retain($channel, $messages);
        }
    }

    /**
     * Stop using Redis, for good, and run this process on its own from here.
     *
     * Once, and permanently, on purpose. Retrying would be the more impressive behaviour and the
     * worse one: a cursor minted locally and a cursor minted by Redis would share one sequence, and
     * a client resuming from one would be served the other's messages as if it had missed them.
     * Splitting the two — this process's messages local, the broker's still from the broker — keeps
     * every cursor meaning what it says. What is lost is cross-worker fan-out until the worker is
     * restarted, which is a thing an operator can see in a log and act on, rather than a sequence
     * of interleaved ids nobody can reason about.
     *
     * @param \Throwable $e The failure that caused the fall-over, for the log line.
     * @return void
     */
    private function fallBack(\Throwable $e): void
    {
        $this->fallback ??= new InProcessPublicationBus(
            origin: $this->origin(),
            defaultRetention: $this->defaultRetention
        );

        foreach ($this->retention as $channel => $messages) {
            $this->fallback->retain($channel, $messages);
        }

        if ($this->reported) {
            return;
        }

        $this->reported = true;

        if ($this->onUnreachable !== null) {
            ($this->onUnreachable)($e);
        }
    }

    /**
     * Everything still inside the retention window, oldest first.
     *
     * @return array<int, array{ch: string, p: string, x: list<string>, o: string}> Cursor => entry.
     */
    private function retained(): array
    {
        $rows = $this->redis->xRange($this->streamKey(), '-', '+');

        if (!is_array($rows)) {
            return [];
        }

        $entries = [];

        foreach ($rows as $fields) {
            $fields = array_map(static fn($value): string => (string) $value, $fields);
            $entries[(int) ($fields['id'] ?? 0)] = [
                'ch' => $fields['ch'] ?? '',
                'p' => $fields['p'] ?? '{}',
                'x' => json_decode($fields['x'] ?? '[]', true) ?: [],
                'o' => $fields['o'] ?? '',
            ];
        }

        ksort($entries);

        return $entries;
    }

    /**
     * The cursors on one channel that may still be replayed, oldest first.
     *
     * The last N entries for that channel, where N is what it retains. Reading it back is what
     * makes a per-channel setting mean anything on a stream Redis can only bound as a whole — and
     * it is also what makes `retention => 0` mean "keep nothing" instead of "keep one".
     *
     * @return array<int, string>
     */
    private function replayable(string $channel): array
    {
        $limit = $this->retention[$channel] ?? $this->defaultRetention;

        if ($limit === 0) {
            return [];
        }

        $onChannel = [];

        foreach ($this->retained() as $cursor => $fields) {
            if ($fields['ch'] === $channel) {
                $onChannel[$cursor] = $fields['p'];
            }
        }

        return $limit > 0 && count($onChannel) > $limit
            ? array_slice($onChannel, -$limit, null, true)
            : $onChannel;
    }

    /** The largest window any channel asked for, which is the only one Redis can enforce. */
    private function streamLimit(): int
    {
        return max(1, max($this->retention ?: [$this->defaultRetention]));
    }

    private function streamKey(): string
    {
        return $this->namespace . ':stream';
    }

    private function cursorKey(): string
    {
        return $this->namespace . ':cursor';
    }
}