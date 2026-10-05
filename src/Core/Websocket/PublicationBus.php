<?php

declare(strict_types=1);

namespace Phue\Core\Websocket;

/**
 * Where a published message enters the system, and how every other process learns about it.
 *
 * A WebSocket connection belongs to one worker process, and a publish is handled by whichever
 * worker served the request that caused it. Without something shared, a publish reaches that
 * worker's subscribers and nobody else's — and the symptom looks exactly like a subscriber that
 * quietly left.
 *
 * Subscribers are not here because they cannot be: a Connection is a live socket, and no other
 * process can write to it. What a message needs in order to be *carried* has no such problem.
 *
 * An implementation must give every process the same cursor for the same message. A client reports
 * the cursor it last saw and is answered from wherever it reconnects, so two processes counting
 * independently is how a reconnecting client gets told it missed messages it received.
 *
 * @package Phue\Core\Websocket
 */
interface PublicationBus
{
    /**
     * Assign a cursor, record the message, and announce it.
     *
     * Announcing is not delivering. The process that publishes delivers to its own subscribers
     * synchronously, because it can, and delivery elsewhere happens as the message comes back
     * around. Doing it the other way round would put a network hop in front of every message on a
     * single-worker deployment, which is the one deployment that should pay nothing.
     *
     * Takes the envelope's fields rather than an encoded envelope, because the cursor is assigned
     * here and has to end up inside the envelope it names. A bus that took a finished envelope
     * would have to either accept an envelope with the wrong id in it or ask the caller to guess
     * the cursor first — and a caller cannot guess it on a shared bus, where the sequence is only
     * knowable by atomically taking the next value.
     *
     * @param list<string> $except Connection ids to skip, for "everyone but the sender". Kept on
     *        the record rather than applied here, because the ids mean nothing in another process.
     * @return array{id: int, payload: string} The cursor, and the envelope that carries it. Both,
     *         because the caller has to write that envelope to its own subscribers and building it
     *         a second time would double the cost of every message on the one path that is meant to
     *         stay free. The cursor stays an integer across every implementation.
     */
    public function publish(string $channel, string $event, mixed $data, array $except = []): array;

    /**
     * The next message this process has not already delivered, blocking until there is one.
     *
     * @return array{id: int, channel: string, payload: string, origin: string, except: list<string>}|null
     */
    public function next(): ?array;

    /**
     * Messages a client missed on one channel, oldest first.
     *
     * @param int $since The last cursor that client saw.
     * @return iterable<array{id: int, payload: string}>
     */
    public function missed(string $channel, int $since): iterable;

    /**
     * The oldest cursor still retained on a channel, or null when nothing is.
     *
     * A client whose cursor is older than this cannot be caught up, and has to be told so rather
     * than handed a partial history that looks complete.
     *
     * @return int|null
     */
    public function oldest(string $channel): ?int;

    /**
     * The most recently assigned cursor, or null when nothing has been published.
     *
     * Sent to a client in a subscription acknowledgement so it has somewhere to resume from
     * without having received a single message yet.
     *
     * @return int|null
     */
    public function cursor(): ?int;

    /**
     * Keep this many messages on a channel.
     */
    public function retain(string $channel, int $messages): void;

    /**
     * Whether messages published in one process reach the subscribers of another.
     *
     * False on a single-worker deployment, where there is no other process to reach. The wiring
     * asks so it can say something rather than assume: a second worker on an unshared bus looks
     * exactly like a subscriber that quietly went away.
     */
    public function isShared(): bool;

    /**
     * A value identifying this process, recorded on every message it publishes.
     *
     * The publishing process delivers to its own subscribers itself, and so must ignore the copy
     * that comes back around. Without this a message reaches a single-worker deployment's
     * subscribers twice.
     */
    public function origin(): string;
}