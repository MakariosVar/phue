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

    /** Messages kept for a topic that was not listed explicitly. */
    private int $defaultRetention;

    /** Where published messages are announced, and where cursors come from. */
    private readonly PublicationBus $bus;

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
    /**
     * @param PublicationBus|null $bus Defaults to in-process: correct for one worker, and free.
     */
    public function __construct(
        callable $sender,
        array $retention = [],
        int $defaultRetention = self::DEFAULT_RETENTION,
        ?PublicationBus $bus = null
    ) {
        $this->sender = $sender;
        $this->defaultRetention = max(0, $defaultRetention);
        $this->bus = $bus ?? new InProcessPublicationBus(defaultRetention: $this->defaultRetention);

        foreach ($retention as $channel => $messages) {
            $this->retain((string) $channel, (int) $messages);
        }
    }

    /** For the consumer that reads published messages back. */
    public function bus(): PublicationBus
    {
        return $this->bus;
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
        // The bus takes the envelope's fields rather than a finished envelope, because the cursor
        // has to end up inside the envelope it names, and only the bus can mint that.
        $published = $this->bus->publish($channel, $event, $data, $except);
        $id = $published['id'];
        $payload = $published['payload'];

        // Delivered here and now, not on the way back around the bus: this process holds these
        // subscribers, and on a single worker that is every delivery there will ever be.
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
     * Deliver a message that arrived here on the bus, to this process's subscribers.
     *
     * Skipping the publisher's own copy is the caller's job, because the caller is the one holding
     * the origin: this method cannot tell "another process said this" from "I said this and it came
     * back around", and delivering both would double every message on a single-worker deployment.
     *
     * @param string $channel Topic to deliver on.
     * @param string $payload Encoded envelope.
     * @param int $id Cursor, recorded on the connection so a reconnect resumes from it.
     * @param list<string> $except Connection ids the publish asked to skip.
     * @return void
     */
    public function deliverFromBus(string $channel, string $payload, int $id, array $except = []): void
    {
        foreach ($this->subscribers[$channel] ?? [] as $connectionId => $connection) {
            if (in_array($connectionId, $except, true)) {
                continue;
            }

            $this->deliver($connection, $payload, $id);
        }
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
        $this->bus->retain($channel, max(0, $messages));
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
     * The most recently assigned cursor.
     *
     * @return int|null Null before anything has been published, so a client is told "nothing yet"
     *         rather than being handed a cursor that would replay the whole retained window.
     */
    public function cursor(): ?int
    {
        return $this->bus->cursor();
    }

    /**
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
        $oldest = $this->bus->oldest($channel);

        if ($oldest === null) {
            return;
        }

        // Compared against since + 1 rather than since: a client that last saw exactly the message
        // before it has missed nothing that survives, and telling it otherwise makes it
        // resynchronise when it did not have to.
        if ($since + 1 < $oldest) {
            $this->deliver($connection, Envelope::encode(Envelope::make(
                $channel,
                'phue.gap',
                ['since' => $since, 'oldest' => $oldest],
                $this->bus->cursor() ?? 0
            )), $this->bus->cursor() ?? 0);

            return;
        }

        foreach ($this->bus->missed($channel, $since) as $message) {
            $this->deliver($connection, $message['payload'], $message['id']);
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