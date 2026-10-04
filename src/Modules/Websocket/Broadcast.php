<?php

declare(strict_types=1);

namespace Phue\Modules\Websocket;

use Phue\Core\Websocket\TopicRegistry;

/**
 * The only WebSocket API an application is expected to touch.
 *
 * Everything below this line — connections, sockets, cursors, reconnect — is the framework's
 * problem. An application broadcasts a message and names a topic; it does not know that a
 * delivery is a socket write, that a subscriber might be a phone that just woke up, or that the
 * message has to be replayed if that phone's connection dropped.
 *
 * @package Phue\Modules\Websocket
 */
final class Broadcast
{
    public function __construct(
        private readonly TopicRegistry $registry,
    ) {
    }

    /**
     * Publish to everyone listening to a topic.
     *
     * @param string $channel Topic name.
     * @param string $event Event name, conventionally `noun.verb` such as `order.paid`.
     * @param mixed $data Payload. Must survive a JSON round trip, because every consumer
     *        receives it as JSON.
     * @param list<string> $except Connection ids to skip, for "everyone but the sender".
     * @return int The cursor assigned to the message.
     */
    public function to(string $channel, string $event, mixed $data = null, array $except = []): int
    {
        return $this->registry->publish($channel, $event, $data, $except);
    }

    /**
     * Publish to one user, on every topic belonging to them.
     *
     * The prefix is the reason this exists. A topic named `orders` is a broadcast to every
     * connected client in the system, which is the wrong default for an application where each
     * user has their own orders; making the unscoped name the deliberate choice, and the scoped
     * one the convenient one, is what stops that being a leak.
     *
     * @param string $userId
     * @param string $event
     * @param mixed $data
     * @param list<string> $except
     * @return int
     */
    public function toUser(string $userId, string $event, mixed $data = null, array $except = []): int
    {
        return $this->to(self::userChannel($userId), $event, $data, $except);
    }

    /**
     * Publish to one user on one of their topics.
     *
     * Separate from toUser() because the two are different intentions and conflating them is how
     * a subscriber ends up waiting on a channel nobody publishes to. toUser() is the user's
     * single stream; this is one kind of event for that user.
     *
     * @param string $userId
     * @param string $suffix Topic suffix, e.g. 'orders'.
     * @param string $event
     * @param mixed $data
     * @param list<string> $except
     * @return int
     */
    public function toUserTopic(
        string $userId,
        string $suffix,
        string $event,
        mixed $data = null,
        array $except = []
    ): int {
        return $this->to(self::userChannel($userId, $suffix), $event, $data, $except);
    }

    /**
     * The topic a user listens on for one kind of event.
     *
     * @param string $userId
     * @param string $suffix
     * @return string
     */
    public static function userChannel(string $userId, string $suffix = ''): string
    {
        $base = 'user:' . $userId;

        return $suffix === '' ? $base : $base . ':' . $suffix;
    }

    /**
     * How many connections are listening to a topic.
     *
     * @param string $channel
     * @return int
     */
    public function listeners(string $channel): int
    {
        return $this->registry->subscriberCount($channel);
    }

    /**
     * @return TopicRegistry
     */
    public function registry(): TopicRegistry
    {
        return $this->registry;
    }
}