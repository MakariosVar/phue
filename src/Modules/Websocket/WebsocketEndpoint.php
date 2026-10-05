<?php

declare(strict_types=1);

namespace Phue\Modules\Websocket;

/**
 * One WebSocket endpoint: a path a client connects to, and everything that path is allowed to do.
 *
 * Endpoints exist rather than a single server-wide setting because authentication and transport
 * are different concerns and they do not change together. An application's own realtime traffic
 * is user data and must never be reachable unauthenticated; a development tool that reloads a page
 * when a file changes carries no user data at all, and demanding a login for it is a tax with no
 * security value behind it. Expressing that as one global "authenticate everything" switch forces
 * one of the two to be wrong.
 *
 * Each endpoint has its own topic registry, which is what makes the setting mean anything. A
 * shared registry would let a connection opened on an open endpoint subscribe to a topic owned by
 * a closed one, and the authentication on this class would be a lock on a door inside a room
 * everyone is already standing in.
 *
 * @package Phue\Modules\Websocket
 */
final class WebsocketEndpoint
{
    /**
     * @param string $name Key used in configuration and by {@see Broadcast::for()}.
     * @param string $endpoint Path a client connects to, without a query string.
     * @param bool $auth Whether a handshake must produce an identity.
     * @param list<string> $allowedOrigins Exact origins, or ['*'] in development only.
     * @param array<string, int> $retention Messages kept per topic, for resuming a reconnect.
     * @param int $defaultRetention Messages kept for a topic that was not listed.
     */
    private function __construct(
        public readonly string $name,
        public readonly string $endpoint,
        public readonly bool $auth,
        public readonly array $allowedOrigins,
        public readonly array $retention,
        public readonly int $defaultRetention,
    ) {
    }

    /**
     * @param array<string, mixed> $raw One entry of the `websockets` map.
     * @param bool $debug Whether the application is in debug mode.
     */
    public static function fromArray(string $name, array $raw, bool $debug = false): self
    {
        $origins = array_values(array_filter(
            array_map(static fn($o): string => trim((string) $o), (array) ($raw['allowed_origins'] ?? []))
        ));

        $retention = [];

        foreach ((array) ($raw['retention'] ?? []) as $topic => $messages) {
            $retention[(string) $topic] = max(0, (int) $messages);
        }

        if ($debug && $origins === []) {
            $origins = ['*'];
        }

        $auth = (bool) ($raw['auth'] ?? true);

        // Authentication off is a hole in the wall of a production deployment, and the mistake is
        // easy to make: copy a development endpoint into production config and the flag travels
        // with it. Refusing it outside debug means the application says so at boot rather than
        // after someone has read data they should not have. There is no debug scenario that
        // needs it, because debug already allows every origin.
        if (!$auth && !$debug) {
            throw new \InvalidArgumentException(sprintf(
                'WebSocket endpoint "%s" has authentication disabled, which is only allowed in debug mode.',
                $name
            ));
        }

        return new self(
            name: $name,
            endpoint: self::normalisePath((string) ($raw['endpoint'] ?? '/ws')),
            auth: $auth,
            allowedOrigins: $origins,
            retention: $retention,
            defaultRetention: max(0, (int) ($raw['default_retention'] ?? 100)),
        );
    }

    /**
     * A path with a guaranteed leading slash and no trailing one, so `/ws`, `ws/` and `/ws/` are
     * one endpoint rather than two that differ only in punctuation and are matched by string
     * comparison at handshake time.
     */
    public static function normalisePath(string $path): string
    {
        // Leading slashes are trimmed before one is added, so '/ws', 'ws' and '//ws' are all the
        // same path rather than three that differ in a way string comparison will not forgive.
        $path = '/' . ltrim(trim($path), '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    /**
     * Whether an Origin header may open a connection to this endpoint.
     *
     * A request with no Origin at all is a non-browser client — a mobile app, a server-side
     * integration, a CLI. Those authenticate with a token instead of a cookie, so the browser-only
     * defence does not apply to them and rejecting them would reject every client that is not a
     * web page. An empty Origin is therefore allowed through, and the token is what decides.
     */
    public function allowsOrigin(?string $origin): bool
    {
        if ($this->allowedOrigins === []) {
            // Default deny. A deployment that never configured this can still serve
            // token-authenticated clients; only cookie-bearing browser ones are blocked.
            return $origin === null || $origin === '';
        }

        if (in_array('*', $this->allowedOrigins, true)) {
            return true;
        }

        if ($origin === null || $origin === '') {
            return true;
        }

        return in_array($origin, $this->allowedOrigins, true);
    }

    /**
     * Returns whether this endpoint retains messages by default.
     * @return bool
     */
    public function retainsByDefault(): bool
    {
        return $this->defaultRetention > 0;
    }

    /**
     * Returns the retention period for a specific topic.
     * @param string $topic
     * @return int
     */
    public function retentionFor(string $topic): int
    {
        return $this->retention[$topic] ?? $this->defaultRetention;
    }

    /**
     * Returns a list of all topics that have specific retention settings.
     * @return list<string>
     */
    public function retainedTopics(): array
    {
        return array_keys($this->retention);
    }
}
