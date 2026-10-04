<?php

declare(strict_types=1);

namespace Phue\Modules\Websocket;

use Phue\Core\Config;

/**
 * Everything an application can turn, resolved from config/websocket.php.
 *
 * The default is deny. Origins are an allowlist and the list starts empty, because the failure
 * that matters is silent: a WebSocket handshake carries the session cookie, browsers do not
 * apply CORS to WebSockets at all, and a page on any origin can therefore open one against this
 * server and read whatever it is subscribed to. That is why every mainstream WebSocket server
 * checks the Origin header by hand.
 *
 * @package Phue\Modules\Websocket
 */
final class WebsocketConfig
{
    /**
     * @param list<string> $allowedOrigins Exact origins, or ['*'] in development only.
     * @param list<string> $retainedTopics Topics that keep history, with their retention passed
     *        separately so the cost is visible where it is configured.
     * @param array<string, int> $retention Messages kept per retained topic.
     * @param int $queueLimit Unread messages a single connection may hold.
     * @param bool $enabled Master switch.
     * @param bool $debug Permissive origins, for development.
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly bool $debug,
        public readonly array $allowedOrigins,
        public readonly array $retainedTopics,
        public readonly array $retention,
        public readonly int $queueLimit,
        public readonly int $defaultRetention,
    ) {
    }

    /**
     * @param array<string, mixed> $raw The `websocket` section of the configuration.
     */
    public static function fromArray(array $raw, bool $debug = false): self
    {
        $enabled = (bool) ($raw['enabled'] ?? $debug);
        $origins = array_values(array_filter(
            array_map(static fn($o): string => trim((string) $o), (array) ($raw['allowed_origins'] ?? []))
        ));

        $retention = [];

        foreach ((array) ($raw['retention'] ?? []) as $topic => $messages) {
            $retention[(string) $topic] = max(0, (int) $messages);
        }

        $defaultRetention = max(0, (int) ($raw['default_retention'] ?? 100));

        // Development may run on any origin — the dev server moves between hosts and ports all
        // the time — but production must not, and the distinction is the debug flag rather than
        // something an application has to remember to set.
        if ($debug && $origins === []) {
            $origins = ['*'];
        }

        return new self(
            enabled: $enabled,
            debug: $debug,
            allowedOrigins: $origins,
            retainedTopics: array_keys($retention),
            retention: $retention,
            queueLimit: max(1, (int) ($raw['queue_limit'] ?? 256)),
            defaultRetention: $defaultRetention,
        );
    }

    /**
     * Resolve from the application configuration.
     */
    public static function fromContainer(Config $config, bool $debug = false): self
    {
        /** @var array<string, mixed> $raw */
        $raw = (array) $config->get('websocket', []);

        return self::fromArray($raw, $debug);
    }

    /**
     * Whether an Origin header may open a connection.
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
     * Whether retention applies to a topic that was not listed explicitly.
     */
    public function retainsByDefault(): bool
    {
        return $this->defaultRetention > 0;
    }

    /**
     * How many messages to keep for a topic.
     */
    public function retentionFor(string $topic): int
    {
        return $this->retention[$topic] ?? $this->defaultRetention;
    }
}