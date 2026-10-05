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
    /**
     * @param callable(string): TopicRegistry $registries Resolves an endpoint name to its
     *        registry. A callable rather than a registry because there is one per endpoint, and
     *        which one a publish belongs to is the caller's decision, not the framework's.
     */
    public function __construct(
        private readonly mixed $registries,
        private readonly ?string $endpoint = null,
    ) {
    }

    /**
     * Scope a broadcast to one endpoint.
     *
     * An endpoint is part of every publish because endpoints do not merely authenticate, they
     * separate: each keeps its own topics, so a message published to one can never reach a
     * subscriber on another. A publish that did not name an endpoint would have to guess, and a
     * guess here is a message delivered to the wrong audience.
     */
    public function for(string $endpoint): self
    {
        return new self($this->registries, $endpoint);
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
        return $this->registry()->publish($channel, $event, $data, $except);
    }

    /**
     * The registry this broadcast is scoped to.
     *
     * Reached with no argument when a publish forgot its `for()`. That used to be an
     * ArgumentCountError from a closure three frames down, which is the worst possible error for
     * the most likely mistake; naming the omission is the difference between a five-second and a
     * five-minute fix.
     *
     * Public because the module's diagnostics reason about one endpoint's topics directly.
     *
     * @throws \InvalidArgumentException When used without an endpoint.
     */
    public function registry(): TopicRegistry
    {
        if ($this->endpoint === null) {
            throw new \InvalidArgumentException(
                'Publish to an endpoint: Broadcast::for(\'app\')->to(...). '
                . 'A publish without an endpoint has no topics to reach, because each endpoint keeps its own.'
            );
        }

        return ($this->registries)($this->endpoint);
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
        return $this->registry()->subscriberCount($channel);
    }

}