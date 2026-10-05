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
     * One registry per endpoint.
     *
     * Per endpoint rather than shared is the point of the whole exercise: it is what makes an
     * endpoint's authentication a real boundary. With one registry, a connection opened on an
     * endpoint that does not authenticate could subscribe to a topic belonging to one that does,
     * and the authentication on that endpoint would be a lock on a door inside a room everybody is
     * already standing in.
     *
     * @var array<string, TopicRegistry>
     */
    private array $registries = [];

    /**
     * Which endpoint a connection arrived on, so a frame knows where to act.
     *
     * Weak keys because a connection is owned by the transport and this is only a lookup while it
     * is alive; holding one here would keep a closed socket's entry alive for the life of the
     * worker.
     *
     * @var \WeakMap<Connection, WebsocketEndpoint>
     */
    private \WeakMap $endpoints;

    /**
     * Connections that were refused, with the code and reason to close them with.
     *
     * @var \WeakMap<Connection, array{0: int, 1: string}>
     */
    private \WeakMap $refusals;

    /**
     * @return void
     */
    public function __construct()
    {
        $this->endpoints = new \WeakMap();
        $this->refusals = new \WeakMap();
    }

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

        $this->container->singleton(
            Broadcast::class,
            fn(): Broadcast => new Broadcast(fn(string $name): TopicRegistry => $this->registry($name))
        );

        /** @var WebSocketTransport $transport */
        $transport = $this->container->get(WebSocketTransport::class);

        $transport->listen(
            fn(Connection $c, Request $request) => $this->onConnect($c, $request),
            fn(Connection $c, string $raw) => $this->onMessage($c, $raw),
            fn(Connection $c) => $this->registryFor($this->endpoints[$c] ?? null)?->detach($c)
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
     * The registry for one endpoint, built on first use.
     *
     * @throws \InvalidArgumentException When no endpoint by that name is configured, because a
     *         publish aimed at nothing is a mistake worth hearing about.
     */
    private function registry(string $name): TopicRegistry
    {
        if (isset($this->registries[$name])) {
            return $this->registries[$name];
        }

        /** @var WebsocketConfig $settings */
        $settings = $this->container->get(WebsocketConfig::class);
        $endpoint = $settings->endpoint($name);

        if ($endpoint === null) {
            throw new \InvalidArgumentException(sprintf(
                'No WebSocket endpoint named "%s". Configured: %s.',
                $name,
                implode(', ', array_keys($settings->endpoints()))
            ));
        }

        /** @var WebSocketTransport $transport */
        $transport = $this->container->get(WebSocketTransport::class);

        $retention = [];

        foreach ($endpoint->retainedTopics() as $topic) {
            $retention[$topic] = $endpoint->retentionFor($topic);
        }

        return $this->registries[$name] = new TopicRegistry(
            static fn(Connection $c, string $payload): bool => $transport->send($c, $payload),
            $retention
        );
    }

    /**
     * The registry a connection belongs to, or null when it has none.
     *
     * A connection with no endpoint is one that arrived on a path nothing is configured for. It
     * is refused on connect; a null here is the second line of defence for a frame that arrives
     * before or after that.
     */
    private function registryFor(?WebsocketEndpoint $endpoint): ?TopicRegistry
    {
        return $endpoint === null ? null : $this->registry($endpoint->name);
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

        // Which endpoint is a question about the path, and it is answered before anything else:
        // every decision below depends on which rules apply, and a connection that matched no
        // endpoint must not be given the benefit of any of them.
        $endpoint = $settings->endpointForPath($request->getUri());

        if ($endpoint === null) {
            $this->refuse($connection, 1008, 'unknown endpoint');

            return;
        }

        // Checked before anything else. A browser does not apply CORS to WebSockets, so an
        // unchecked Origin lets any page on the internet open a connection carrying the user's
        // session cookie and read whatever it subscribes to.
        if (!$endpoint->allowsOrigin($request->getHeader('Origin'))) {
            $this->refuse($connection, 1008, 'origin not allowed');

            return;
        }

        // The identity is resolved even when the endpoint does not require one. A development
        // channel that knows who is watching is more useful than one that knows nobody, and
        // refusing to look is what would make the setting mean "this data is public".
        $identity = $this->resolveIdentity($request);

        if ($identity !== null) {
            $connection->identify($identity);
        }

        if ($identity === null && $endpoint->auth) {
            $this->refuse($connection, 1008, 'authentication required');

            return;
        }

        // Recorded last, and only once the connection has been accepted. Recording it earlier
        // leaves a refused connection still knowing its endpoint, which means it can subscribe
        // and receive everything published there — the authentication check would be closing the
        // socket and leaving the door open behind it.
        $this->endpoints[$connection] = $endpoint;
    }

    /**
     * Refuse a handshake, and say why in a way the client can act on.
     *
     * The reason is part of the protocol rather than a log line because the client that most needs
     * to know is a browser with no console open: a developer staring at a toolbar that never
     * reloads is otherwise left guessing between "wrong password", "wrong host" and "not
     * configured", and the close code is the only thing that distinguishes them.
     */
    private function refuse(Connection $connection, int $code, string $reason): void
    {
        // Recorded rather than acted on, because closing during the handshake does not take
        // effect: the socket has not finished registering, and the call is quietly ignored. The
        // close happens on the client's first frame instead, which is both the first moment the
        // socket is known to be usable and something a client that connected always sends.
        //
        // Closing here as well would look more correct and would be worse: the transport drops
        // the connection from its table on close, so the retry would find nothing to close and
        // the refusal would never reach the client at all.
        $this->refusals[$connection] = [$code, $reason];
    }

    /**
     * @return void
     */
    public function onMessage(Connection $connection, string $raw): void
    {
        // A refused connection is closed here rather than in the handshake, because this is the
        // first moment the socket is known to be usable. Nothing it says is acted on either way.
        if (isset($this->refusals[$connection])) {
            [$code, $reason] = $this->refusals[$connection];
            unset($this->refusals[$connection]);

            $this->container->get(WebSocketTransport::class)->close($connection, $code, $reason);

            return;
        }

        $registry = $this->registryFor($this->endpoints[$connection] ?? null);

        // A frame from a connection this module never accepted has no endpoint, so it has no
        // registry and no topics to touch. Silently dropping it is the whole handling.
        if ($registry === null) {
            return;
        }

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

        // Checked before the token because a browser tab carries a session cookie and nothing
        // else. Trying the token first would mean a logged-in developer is asked for a token
        // their browser could have answered for.
        $fromSession = $this->identityFromSession($request);

        if ($fromSession !== null) {
            return $fromSession;
        }

        return $this->identityFromBearer($request);
    }

    /**
     * The session path, which is what a browser actually has.
     *
     * Only available when the Auth module is loaded, because a WebSocket handshake has no
     * business knowing how an application stores its logins. Best effort: a handshake that
     * cannot read a session is an unauthenticated handshake, which the caller rejects. It
     * must not be an exception, because that would turn a logged-out visitor into a server
     * error instead of a refused connection.
     */
    private function identityFromSession(Request $request): ?string
    {
        if (!$this->container->has(\Phue\Modules\Auth\AuthManager::class)) {
            return null;
        }

        try {
            $identity = $this->container->get(\Phue\Modules\Auth\AuthManager::class)
                ->authenticate($request);
        } catch (\Throwable) {
            return null;
        }

        if ($identity === null) {
            return null;
        }

        $id = (string) $identity->getId();

        return $id === '' ? null : $id;
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
        /** @var WebsocketConfig $settings */
        $settings = $this->container->get(WebsocketConfig::class);
        $endpoints = [];

        // Every endpoint is reported, not just one, because the number that matters when
        // debugging is per endpoint: a dev channel with no listeners next to an app channel with
        // many is a completely different problem from both being empty.
        foreach (array_keys($settings->endpoints()) as $name) {
            $registry = $this->registries[$name] ?? null;
            $endpoints[$name] = [
                'endpoint' => $settings->endpoint($name)?->endpoint,
                'auth' => $settings->endpoint($name)?->auth,
                'topics' => $registry?->channels() ?? [],
                'listeners' => $registry === null ? 0 : count($registry->channels()),
            ];
        }

        return Response::json([
            'v' => Envelope::VERSION,
            'endpoints' => $endpoints,
        ])->setHeader('Cache-Control', 'no-store');
    }
}