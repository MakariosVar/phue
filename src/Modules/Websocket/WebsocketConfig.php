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
     * @param array<string, WebsocketEndpoint> $endpoints Named endpoints, keyed by name.
     * @param int $queueLimit Unread messages a single connection may hold.
     * @param bool $enabled Master switch.
     * @param bool $debug Permissive origins and a dev endpoint, for development.
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly bool $debug,
        public readonly array $endpoints,
        public readonly int $queueLimit,
    ) {
    }

    /**
     * @param array<string, mixed> $raw The `websocket` section of the configuration.
     */
    public static function fromArray(array $raw, bool $debug = false): self
    {
        $enabled = (bool) ($raw['enabled'] ?? $debug);
        $endpoints = [];
        $configured = (array) ($raw['websockets'] ?? []);

        foreach ($configured as $name => $entry) {
            $endpoints[(string) $name] = WebsocketEndpoint::fromArray(
                (string) $name,
                (array) $entry,
                $debug
            );
        }

        // Whether the application described any endpoint is answered by what it wrote, not by
        // what has been merged since. Adding the development endpoint first and asking this
        // question afterwards would mean a debug server had no application endpoint at all, which
        // is the opposite of what "debug" is supposed to add.
        $describedAny = $configured !== [];

        // Development may run on any origin — the dev server moves between hosts and ports all
        // the time — but production must not, and the distinction is the debug flag rather than
        // something an application has to remember to set.
        //
        // This is the one endpoint every application gets without asking: authenticated, on /ws.
        // It exists so that enabling the module does not also mean designing its surface, and it
        // is deliberately the strict one. Anything looser has to be written down.
        if ($debug && !isset($endpoints[self::DEV_ENDPOINT])) {
            $endpoints[self::DEV_ENDPOINT] = WebsocketEndpoint::fromArray(
                self::DEV_ENDPOINT,
                ['endpoint' => self::DEV_ENDPOINT_PATH, 'auth' => false],
                true
            );
        }

        if (!$describedAny) {
            $endpoints['app'] = WebsocketEndpoint::fromArray('app', ['endpoint' => '/ws'], $debug);
        }

        return new self(
            enabled: $enabled,
            debug: $debug,
            endpoints: $endpoints,
            queueLimit: max(1, (int) ($raw['queue_limit'] ?? 256)),
        );
    }

    /** Name of the endpoint the development toolbar connects to. */
    public const DEV_ENDPOINT = 'dev';

    /**
     * Path the development endpoint listens on.
     *
     * Under the framework's own prefix rather than a bare name, so a development channel cannot
     * be mistaken for an application one, and so the client that connects to it can be written
     * against a constant instead of a string that has to match the configuration.
     */
    public const DEV_ENDPOINT_PATH = '/_phue/ws';

    /**
     * @return array<string, WebsocketEndpoint>
     */
    public function endpoints(): array
    {
        return $this->endpoints;
    }

    /**
     * The endpoint a handshake path belongs to, or null when the path is not one of them.
     *
     * A null here is what keeps the handshake path from being a catch-all: an unmatched path gets
     * an ordinary HTTP response instead of an upgrade, so adding a second endpoint cannot quietly
     * widen what the first one already accepts.
     */
    public function endpointForPath(string $path): ?WebsocketEndpoint
    {
        // The query string is not part of the path, and a token commonly rides in it.
        $path = strtok($path, '?') ?: $path;
        $path = WebsocketEndpoint::normalisePath($path);

        foreach ($this->endpoints as $endpoint) {
            if ($endpoint->endpoint === $path) {
                return $endpoint;
            }
        }

        return null;
    }

    public function endpoint(string $name): ?WebsocketEndpoint
    {
        return $this->endpoints[$name] ?? null;
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

}
