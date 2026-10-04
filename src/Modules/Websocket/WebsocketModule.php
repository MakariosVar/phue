<?php

declare(strict_types=1);

namespace Phue\Modules\Websocket;

use Phue\Core\Config;
use Phue\Core\Container;
use Phue\Core\Module;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Websocket\Connection;
use Phue\Core\Websocket\Envelope;
use Phue\Core\Websocket\TopicRegistry;
use Phue\Core\Websocket\WebSocketTransport;

/**
 * Real-time channels for the application.
 *
 * Registers nothing that a deployment without this module could reach, and holds nothing that
 * survives a request. The topic registry is the only state, and it is a singleton because "what
 * did this client miss" is not a question a fresh object can answer.
 *
 * @package Phue\Modules\Websocket
 */
class WebsocketModule extends Module
{
    /**
     * Module identifier.
     */
    public string $id = 'websocket';

    /**
     * No hard dependency on Auth. An application may authenticate WebSockets with its own
     * mechanism, and forcing this module to wait for Auth would make the transport unavailable
     * to exactly the applications that do not use it.
     *
     * @var array<int, string>
     */
    public array $requires = [];

    /**
     * @var null|callable(Request): ?string Returns the identity, or null to refuse.
     */
    private $authenticate;

    /**
     * @return void
     */
    public function init(): void
    {
        $this->config();
        $this->bindings();
    }

    /**
     * @return void
     */
    protected function config(): void
    {
        $this->container->singleton(
            WebsocketConfig::class,
            function (): WebsocketConfig {
                $config = $this->container->get(Config::class);
                $debug = (bool) $config->get('app.debug', false);

                return WebsocketConfig::fromContainer($config, $debug);
            }
        );
    }

    /**
     * @return void
     */
    protected function bindings(): void
    {
        /** @var WebsocketConfig $settings */
        $settings = $this->container->get(WebsocketConfig::class);

        if (!$settings->enabled) {
            return;
        }

        // Resolved from the configured class and bound by interface. Naming the concrete class
        // here would make the transport unreplaceable, which is the one thing this abstraction
        // exists to prevent — and a transport that cannot be substituted cannot be tested
        // without a server, or swapped for another one later.
        $transportClass = $this->container->get(Config::class)->get(
            'websocket.transport',
            SwooleWebSocketTransport::class
        );

        $this->container->singleton(
            WebSocketTransport::class,
            static fn(Container $c): object => $c->get(
                is_string($transportClass) ? $transportClass : SwooleWebSocketTransport::class
            )
        );

        $this->container->singleton(TopicRegistry::class, function () use ($settings): TopicRegistry {
            /** @var WebSocketTransport $transport */
            $transport = $this->container->get(WebSocketTransport::class);

            $retention = [];

            foreach ($settings->retainedTopics as $topic) {
                $retention[$topic] = $settings->retentionFor($topic);
            }

            return new TopicRegistry(
                static fn(Connection $c, string $payload): bool => $transport->send($c, $payload),
                $retention
            );
        });

        $this->container->singleton(
            Broadcast::class,
            fn(): Broadcast => new Broadcast($this->container->get(TopicRegistry::class))
        );

        /** @var WebSocketTransport $transport */
        $transport = $this->container->get(WebSocketTransport::class);

        $transport->listen(
            fn(Connection $c, Request $request) => $this->onConnect($c, $request),
            fn(Connection $c, string $raw) => $this->onMessage($c, $raw),
            fn(Connection $c) => $this->container->get(TopicRegistry::class)->detach($c)
        );

        // Handed to whichever server the application runs. Keyed rather than injected because
        // the transport is a singleton the server creates, not one the module owns.
        $this->container->singleton('phue.server.attachers', static fn(): array => [
            static function ($server) use ($transport): void {
                $transport->attach($server);
            },
        ]);
    }

    /**
     * Install the application-supplied authenticator.
     *
     * The framework does not decide who may connect. It can authenticate a session when the Auth
     * module is present, but a token, an API key or an HMAC are equally reasonable, and baking
     * one in would make this module unusable for the others.
     *
     * @param callable(Request): ?string $authenticate
     * @return void
     */
    public function authenticateWith(callable $authenticate): void
    {
        $this->authenticate = $authenticate;
    }

    /**
     * @return void
     */
    public function onConnect(Connection $connection, Request $request): void
    {
        /** @var WebsocketConfig $settings */
        $settings = $this->container->get(WebsocketConfig::class);

        // Checked before anything else. A browser does not apply CORS to WebSockets, so an
        // unchecked Origin lets any page on the internet open a connection carrying the user's
        // session cookie and read whatever it subscribes to.
        if (!$settings->allowsOrigin($request->getHeader('Origin'))) {
            $this->container->get(WebSocketTransport::class)
                ->close($connection, 1008, 'origin not allowed');

            return;
        }

        $identity = $this->resolveIdentity($request);

        if ($identity === null) {
            $this->container->get(WebSocketTransport::class)
                ->close($connection, 1008, 'authentication required');

            return;
        }

        $connection->identify($identity);
    }

    /**
     * @return void
     */
    public function onMessage(Connection $connection, string $raw): void
    {
        /** @var TopicRegistry $registry */
        $registry = $this->container->get(TopicRegistry::class);
        $message = Envelope::decodeInbound($raw);

        // A malformed frame is not fatal. A client that sends rubbish should get silence and
        // the chance to correct itself, not a connection it cannot reopen.
        if ($message === null) {
            return;
        }

        switch ($message['act']) {
            case 'sub':
                if ($message['ch'] !== '') {
                    $registry->subscribe($connection, $message['ch'], $message['since']);
                }
                break;

            case 'unsub':
                $registry->unsubscribe($connection, $message['ch']);
                break;

            case 'ping':
                // Answered so a client can measure liveness and so an intermediary does not
                // decide the connection is idle. Sent to the one client that asked rather than
                // broadcast, and without requiring it to be subscribed to anything.
                $registry->sendTo($connection, $message['ch'], 'phue.pong');
                break;
        }
    }

    /**
     * The identity behind a handshake request, or null when there is none.
     */
    private function resolveIdentity(Request $request): ?string
    {
        if ($this->authenticate !== null) {
            $identity = ($this->authenticate)($request);

            if (is_string($identity) && $identity !== '') {
                return $identity;
            }
        }

        return $this->identityFromBearer($request);
    }

    /**
     * The bearer token fallback.
     *
     * Taken from the Authorization header, or from a query parameter because a WebSocket client
     * in a native runtime does not reliably send custom headers. The query form puts the token
     * in places that log URLs, so the token behind it must be short-lived and revocable through
     * whatever issued it — which is a decision for the application, and the reason the module
     * does not issue tokens itself.
     */
    private function identityFromBearer(Request $request): ?string
    {
        $header = trim((string) $request->getHeader('Authorization', ''));

        if (stripos($header, 'bearer ') === 0) {
            $token = trim(substr($header, 7));

            return $token === '' ? null : $token;
        }

        $token = trim((string) ($request->getQueryParams()['token'] ?? ''));

        return $token === '' ? null : $token;
    }

    /**
     * A JSON description of the endpoint, for diagnostics and for a client that only wants to
     * know whether it exists.
     *
     * @return Response
     */
    public function describe(): Response
    {
        /** @var TopicRegistry $registry */
        $registry = $this->container->get(TopicRegistry::class);

        return Response::json([
            'v' => Envelope::VERSION,
            'topics' => $registry->channels(),
            'listeners' => count($registry->channels()),
        ])->setHeader('Cache-Control', 'no-store');
    }
}