<?php

declare(strict_types=1);

namespace Phue\Core\Websocket;

/**
 * Which connections are listening to which topic, and what they missed while they were away.
 *
 * Two properties carry the whole design.
 *
 * Fan-out is one publish and N deliveries, with no per-subscriber work beyond the send itself.
 * A topic with a thousand subscribers costs a thousand sends because the protocol requires a
 * thousand deliveries — not a thousand times the bookkeeping. That is the difference between
 * this being usable at scale and being a demo.
 *
 * Replay is why the cursor exists. A phone that was backgrounded has an OS-suspended socket: the
 * connection was open and the client was not listening, and on resume it must not be handed the
 * next event as though nothing happened. Every published message can be retained per topic, and
 * a reconnecting client says the last id it saw and is handed the rest.
 *
 * Retention is per topic and opt-in, because the memory is proportional to it. A topic nobody
 * replays should cost nothing.
 *
 * @package Phue\Core\Websocket
 */
final class TopicRegistry
{
    /**
     * Default messages kept per topic when a topic retains at all.
     */
    public const DEFAULT_RETENTION = 100;

    /**
     * channel => connection id => connection
     *
     * @var array<string, array<string, Connection>>
     */
    private array $subscribers = [];

    /**
     * channel => [cursor => encoded message], ascending.
     *
     * @var array<string, array<int, string>>
     */
    private array $history = [];

    /**
     * How many messages each retaining topic keeps.
     *
     * @var array<string, int>
     */
    private array $retention = [];

    /** Messages kept for a topic that was not listed explicitly. */
    private int $defaultRetention;

    /**
     * The last cursor handed out.
     *
     * @var int
     */
    private int $cursor = 0;

    /**
     * Sends one encoded message to one connection.
     *
     * A callable rather than a transport reference so that Core knows nothing about Swoole, and
     * so that a test can capture deliveries without a server.
     *
     * @var callable(Connection, string): bool
     */
    private $sender;

    /**
     * @param callable(Connection, string): bool $sender Returns whether the message was
     *        accepted. Takes the connection rather than its id so the transport can read the
     *        queue state and decide for itself whether the send is worth making.
     * @param array<string, int> $retention Messages to keep per topic, by topic name.
     */
    public function __construct(callable $sender, array $retention = [], int $defaultRetention = self::DEFAULT_RETENTION)
    {
        $this->sender = $sender;
        $this->defaultRetention = max(0, $defaultRetention);

        foreach ($retention as $channel => $messages) {
            $this->retain((string) $channel, (int) $messages);
        }
    }

    /**
     * Announce an event to everyone listening to a topic.
     *
     * @param string $channel
     * @param string $event
     * @param mixed $data
     * @param list<string> $except Connection ids to skip, for "everyone but the sender".
     * @return int The cursor assigned to this message.
     */
    public function publish(string $channel, string $event, mixed $data, array $except = []): int
    {
        $id = ++$this->cursor;
        $payload = Envelope::encode(Envelope::make($channel, $event, $data, $id));

        $this->remember($channel, $id, $payload);

        foreach ($this->subscribers[$channel] ?? [] as $connectionId => $connection) {
            if (in_array($connectionId, $except, true)) {
                continue;
            }

            $this->deliver($connection, $payload, $id);
        }

        return $id;
    }

    /**
     * Deliver one message to one connection, without involving a topic.
     *
     * For the answers a protocol owes a single client — a pong, an acknowledgement, an error —
     * which must not be broadcast to everyone else listening, and must not require the client to
     * have subscribed to the topic in order to be told it was heard.
     *
     * @param Connection $connection
     * @param string $channel Recorded on the envelope so a client routing by topic can place it.
     * @param string $event
     * @param mixed $data
     * @return void
     */
    public function sendTo(Connection $connection, string $channel, string $event, mixed $data = null): void
    {
        $id = $connection->lastSeen();

        $this->deliver(
            $connection,
            Envelope::encode(Envelope::make($channel, $event, $data, $id)),
            $id
        );
    }

    /**
     * Start listening to a topic, replaying what was missed if the client says where it was.
     *
     * @param Connection $connection
     * @param string $channel
     * @param null|int $since The last cursor this client saw, or null for a fresh subscription.
     * @return void
     */
    public function subscribe(Connection $connection, string $channel, ?int $since = null): void
    {
        $connection->join($channel);
        $this->subscribers[$channel][$connection->id()] = $connection;

        if ($since === null) {
            return;
        }

        $this->replay($connection, $channel, $since);
    }

    /**
     * Stop listening to one topic.
     *
     * @param Connection $connection
     * @param string $channel
     * @return void
     */
    public function unsubscribe(Connection $connection, string $channel): void
    {
        $connection->leave($channel);
        unset($this->subscribers[$channel][$connection->id()]);

        if (($this->subscribers[$channel] ?? null) === []) {
            unset($this->subscribers[$channel]);
        }
    }

    /**
     * Remove a connection from every topic it was in.
     *
     * Called when a socket closes. Leaving subscriptions behind is how a registry grows without
     * bound: a client that reconnects ten times a day leaves ten entries, and every publish keeps
     * trying to write to a socket that no longer exists.
     *
     * @param Connection $connection
     * @return void
     */
    public function detach(Connection $connection): void
    {
        foreach ($connection->channels() as $channel) {
            unset($this->subscribers[$channel][$connection->id()]);

            if (($this->subscribers[$channel] ?? null) === []) {
                unset($this->subscribers[$channel]);
            }
        }

        $connection->forgetChannels();
    }

    /**
     * Keep the last N messages of a topic so a reconnecting client can be caught up.
     *
     * @param string $channel
     * @param int $messages
     * @return void
     */
    public function retain(string $channel, int $messages = self::DEFAULT_RETENTION): void
    {
        $this->retention[$channel] = max(0, $messages);

        if ($messages === 0) {
            unset($this->history[$channel]);
        }
    }

    /**
     * @param string $channel
     * @return int
     */
    public function subscriberCount(string $channel): int
    {
        return count($this->subscribers[$channel] ?? []);
    }

    /**
     * Connections listening to at least one topic.
     *
     * Summed per channel it would count a connection once per topic, which is not what anyone
     * asking "how many clients are connected" means.
     *
     * @return int
     */
    public function totalSubscribers(): int
    {
        $ids = [];

        foreach ($this->subscribers as $connections) {
            foreach ($connections as $id => $_) {
                $ids[$id] = true;
            }
        }

        return count($ids);
    }

    /**
     * @return int
     */
    public function cursor(): int
    {
        return $this->cursor;
    }

    /**
     * @param string $channel
     * @return list<string>
     */
    public function channels(): array
    {
        return array_keys($this->subscribers);
    }

    /**
     * @param Connection $connection
     * @param string $channel
     * @param int $since
     * @return void
     */
    private function replay(Connection $connection, string $channel, int $since): void
    {
        // The same default as remember(): an unlisted topic is retained at the endpoint's
        // default, not at nothing. Reading zero here meant the two disagreed — the history was
        // recorded and then declared unreplayable — so retention appeared configured and did
        // nothing.
        if (($this->retention[$channel] ?? $this->defaultRetention) === 0) {
            return;
        }

        $history = $this->history[$channel] ?? [];

        if ($history === []) {
            return;
        }

        // The oldest thing still kept is the boundary, and the comparison is against
        // since + 1 rather than since: a client that last saw exactly the message before it has
        // missed nothing that survives, and telling it otherwise makes it resynchronise when it
        // did not have to. Only a client whose gap starts before the window is incomplete.
        if ($since + 1 < $this->oldestRetained($channel)) {
            $this->deliver($connection, Envelope::encode(Envelope::make(
                $channel,
                'phue.gap',
                ['since' => $since, 'oldest' => $this->oldestRetained($channel)],
                $this->cursor
            )), $this->cursor);

            return;
        }

        foreach ($history as $id => $payload) {
            if ($id > $since) {
                $this->deliver($connection, $payload, $id);
            }
        }
    }

    /**
     * @param string $channel
     * @return int
     */
    private function oldestRetained(string $channel): int
    {
        $history = $this->history[$channel] ?? [];

        return $history === [] ? $this->cursor + 1 : (int) array_key_first($history);
    }

    /**
     * @param string $channel
     * @param int $id
     * @param string $payload
     * @return void
     */
    private function remember(string $channel, int $id, string $payload): void
    {
        // Falls back to the endpoint's default rather than to nothing. Reading zero here made
        // `default_retention` a documented key with no effect: every unlisted topic was silently
        // unresumable, which is the one thing a client cannot detect for itself.
        $limit = $this->retention[$channel] ?? $this->defaultRetention;

        if ($limit === 0) {
            return;
        }

        $this->history[$channel][$id] = $payload;

        // Unset by key, never array_shift(): shifting an integer-keyed array renumbers the keys
        // from zero, which would replace the cursor ids with sequence positions and make every
        // replay comparison wrong — a client asking for "since 5" would be told it had missed
        // nothing when in fact the retained window no longer reached back that far.
        while (count($this->history[$channel]) > $limit) {
            $oldest = array_key_first($this->history[$channel]);

            if ($oldest === null) {
                break;
            }

            unset($this->history[$channel][$oldest]);
        }
    }

    /**
     * @param Connection $connection
     * @param string $payload
     * @param int $id
     * @return void
     */
    private function deliver(Connection $connection, string $payload, int $id): void
    {
        if ($connection->isClosed()) {
            $this->detach($connection);

            return;
        }

        // Refusing up front is the whole point of the queue ceiling: the message is discarded
        // here rather than buffered somewhere the connection can no longer drain.
        if (!$connection->reserve()) {
            return;
        }

        $sent = ($this->sender)($connection, $payload);

        if ($sent) {
            $connection->saw($id);
            $connection->release();

            return;
        }

        // The send failed outright, so the slot stays taken and the cursor is not advanced. A
        // client that reconnects asking for its last seen id is then handed what it missed.
    }
}