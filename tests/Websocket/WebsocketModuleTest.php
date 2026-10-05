<?php

declare(strict_types=1);

namespace Phue\Tests\Websocket;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Config;
use Phue\Core\Container;
use Phue\Core\EventDispatcher;
use Phue\Core\Routing\Router;
use Phue\Core\Websocket\Connection;
use Phue\Core\Websocket\TopicRegistry;
use Phue\Core\Websocket\WebSocketTransport;
use Phue\Modules\Websocket\Broadcast;
use Phue\Modules\Websocket\SwooleWebSocketTransport;
use Phue\Modules\Websocket\WebsocketConfig;
use Phue\Modules\Websocket\WebsocketModule;
use Phue\Tests\Websocket\Support\FakeTransport;

/**
 * What the module is allowed to do to a client that connects.
 *
 * The Origin check and the authentication are the security boundary of the whole feature, and
 * they are easy to write in a way that looks right and is not: a browser does not apply CORS to
 * WebSockets, and the handshake carries the session cookie. Every assertion here exists because
 * the failure it guards against is silent — the connection works, and it works for the wrong
 * people.
 *
 * @package Phue\Tests\Websocket
 */
#[Group('unit')]
final class WebsocketModuleTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    private Container $container;
    private string $configDir = '';

    private FakeTransport $transport;
    private ?TopicRegistry $registry;
    private WebsocketModule $module;

    /**
     * Boot the module over a fake transport.
     *
     * The registry is the one the module builds for itself, not one assembled here: wiring is
     * half of what this module does, and a test that supplied its own registry would pass
     * against wiring that was never used.
     */
    private function boot(array $config = [], bool $debug = false, ?bool $enabled = true): void
    {
        // Left out entirely when null, so the debug fallback in the config can be exercised
        // without a config key overriding it.
        if ($enabled !== null) {
            $config['enabled'] = $enabled;
        }

        // Before the file is written, not after: the module reads this from the config, so
        // setting it later leaves the real transport in place and the fake unwired.
        $config['transport'] = FakeTransport::class;

        // Everything in the passed array describes one endpoint, so these tests keep reading as
        // "the app endpoint" rather than having to name it at every call site. Defaults are
        // merged rather than replaced so a test can set one key and inherit the rest.
        $config['websockets'] = ['app' => array_merge([
            'endpoint' => '/ws',
            'auth' => true,
            'default_retention' => 0,
        ], $config)];

        $dir = sys_get_temp_dir() . '/phue_ws_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        file_put_contents($dir . '/app.php', sprintf(
            '<?php return ["debug" => %s];',
            $debug ? 'true' : 'false'
        ));
        file_put_contents(
            $dir . '/websocket.php',
            '<?php return ' . var_export($config, true) . ';'
        );

        $this->container = new Container();
        $this->configDir = $dir;
        $this->container->singleton(Config::class, static fn(): Config => new Config($dir));

        $this->transport = new FakeTransport();
        $this->container->singleton(FakeTransport::class, fn(): FakeTransport => $this->transport);

        $this->module = new WebsocketModule();
        $this->module->setDependencies(new Router(), $this->container, new EventDispatcher());
        $this->module->init();

        // Not bound when the module is off, and there is nothing to register in that case.
        // Reached through Broadcast rather than from the container, because a registry now belongs
        // to an endpoint and asking the container for "the" registry is the ambiguity the change
        // removed.
        $this->registry = $this->container->get(WebsocketConfig::class)->enabled
            ? $this->container->get(Broadcast::class)->for('app')->registry()
            : null;
    }

    /**
     * Connect, then say something.
     *
     * A refusal cannot be delivered during the handshake — the socket is not registered yet — so
     * it is sent when the client's first frame arrives. Every real client sends one; a test that
     * only connects would therefore see a refusal that never lands and pass for the wrong reason.
     * The channel is empty on purpose, so it subscribes to nothing and sends nothing.
     */
    private function handshake(Connection $connection, array $headers = [], array $query = [], string $path = '/ws'): Connection
    {
        $this->transport->connect($connection, $headers, $query, $path);
        $this->transport->message($connection, '{"act":"sub","ch":""}');

        return $connection;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->tempDirs as $dir) {
            foreach ((array) glob($dir . '/*.php') as $file) {
                @unlink((string) $file);
            }

            @rmdir($dir);
        }

        $this->tempDirs = [];
    }

    // ------------------------------------------------------------------- the boundary

    public function testAnUnauthenticatedConnectionIsClosed(): void
    {
        $this->boot();
        $this->module->authenticateWith(static fn(): ?string => null);

        $connection = new Connection('fd1');
        $this->handshake($connection);

        $this->assertCount(1, $this->transport->closed);
        $this->assertSame(1008, $this->transport->closed[0][1]);
    }

    public function testAnAuthenticatedConnectionIsAccepted(): void
    {
        $this->boot();
        $this->module->authenticateWith(static fn(): ?string => 'user:42');

        $connection = new Connection('fd1');
        $this->handshake($connection);

        $this->assertSame([], $this->transport->closed);
        $this->assertSame('user:42', $connection->identity());
    }

    public function testABearerTokenInTheHeaderIsAnIdentity(): void
    {
        $this->boot();

        $connection = new Connection('fd1');
        $this->handshake($connection, headers: ['Authorization' => 'Bearer tok_abc']);

        $this->assertSame([], $this->transport->closed);
        $this->assertSame('tok_abc', $connection->identity());
    }

    public function testABearerTokenInTheQueryIsAnIdentity(): void
    {
        $this->boot();

        // A native WebSocket cannot be relied on to send custom headers, so the token may have
        // to ride in the query string.
        $connection = new Connection('fd1');
        $this->handshake($connection, query: ['token' => 'tok_xyz']);

        $this->assertSame('tok_xyz', $connection->identity());
    }

    public function testAMalformedAuthorizationHeaderIsNotAnIdentity(): void
    {
        $this->boot();

        $connection = new Connection('fd1');
        $this->handshake($connection, headers: ['Authorization' => 'Basic dXNlcjpwdw==']);

        $this->assertCount(1, $this->transport->closed);
    }

    // ---------------------------------------------------------------------- origins

    public function testADisallowedBrowserOriginIsRefused(): void
    {
        $this->boot(['allowed_origins' => ['https://app.example.com']]);
        $this->module->authenticateWith(static fn(): ?string => 'user:42');

        $connection = new Connection('fd1');
        $this->handshake($connection, headers: ['Origin' => 'https://evil.example']);

        $this->assertCount(1, $this->transport->closed);
        $this->assertSame(1008, $this->transport->closed[0][1]);
    }

    public function testAnAllowedBrowserOriginIsAccepted(): void
    {
        $this->boot(['allowed_origins' => ['https://app.example.com']]);
        $this->module->authenticateWith(static fn(): ?string => 'user:42');

        $connection = new Connection('fd1');
        $this->handshake($connection, headers: ['Origin' => 'https://app.example.com']);

        $this->assertSame([], $this->transport->closed);
    }

    public function testWithNoOriginsConfiguredABrowserIsStillRefused(): void
    {
        $this->boot();
        $this->module->authenticateWith(static fn(): ?string => 'user:42');

        $connection = new Connection('fd1');
        $this->handshake($connection, headers: ['Origin' => 'https://anywhere.example']);

        // Default deny. A deployment that configured nothing must not silently accept every
        // origin in the world.
        $this->assertCount(1, $this->transport->closed);
    }

    public function testAClientWithNoOriginIsNotBlockedByTheOriginRule(): void
    {
        $this->boot();
        $this->module->authenticateWith(static fn(): ?string => 'user:42');

        // A phone or a server sends no Origin at all, and rejects it here would reject every
        // client that is not a web page.
        $connection = new Connection('fd1');
        $this->handshake($connection);

        $this->assertSame([], $this->transport->closed);
    }

    public function testDebugTurnsAnEmptyOriginListIntoAnyOrigin(): void
    {
        $this->boot(debug: true);
        $this->module->authenticateWith(static fn(): ?string => 'user:42');

        $connection = new Connection('fd1');
        $this->handshake($connection, headers: ['Origin' => 'http://192.168.1.5:8080']);

        $this->assertSame([], $this->transport->closed, 'the dev server moves between hosts and ports all the time');
    }

    // -------------------------------------------------------------------- messaging

    public function testSubscribingDeliversLaterBroadcasts(): void
    {
        $this->boot();
        $this->module->authenticateWith(static fn(): ?string => 'user:42');

        $connection = new Connection('fd1');
        $this->handshake($connection);
        $this->transport->message($connection, json_encode(['act' => 'sub', 'ch' => 'user:42:orders']));

        $this->container->get(Broadcast::class)->for('app')->toUserTopic('42', 'orders', 'order.paid', ['id' => 9]);

        $messages = $this->published();

        $this->assertCount(1, $messages);
        $this->assertSame('user:42:orders', $messages[0]['ch']);
        $this->assertSame('order.paid', $messages[0]['ev']);
        $this->assertSame(['id' => 9], $messages[0]['d']);
    }

    /**
     * A subscription is acknowledged, because an open socket is not evidence that the channel
     * works.
     *
     * A server with this module configured but switched off still completes the handshake and
     * accepts the connection, then says nothing — indefinitely. A client that treated "connected"
     * as "working" stopped its fallback at exactly that moment: an open socket, a subscribed topic,
     * and no live reload, with nothing in the logs. An acknowledgement is the only proof that lands
     * before any data has moved.
     */
    public function testASubscriptionIsAcknowledged(): void
    {
        $this->boot();
        $this->module->authenticateWith(static fn(): ?string => 'user:42');

        $connection = new Connection('fd1');
        $this->handshake($connection);
        $this->transport->message($connection, json_encode(['act' => 'sub', 'ch' => 'orders']));

        $messages = $this->transport->decoded();

        $this->assertCount(1, $messages, 'one frame, answering the subscription');
        $this->assertSame('phue.subscribed', $messages[0]['ev']);
        $this->assertSame('orders', $messages[0]['ch']);
        $this->assertSame('orders', $messages[0]['d']['ch']);
    }

    public function testAnEmptySubscriptionChannelIsNotAcknowledged(): void
    {
        $this->boot();
        $this->module->authenticateWith(static fn(): ?string => 'user:42');

        $connection = new Connection('fd1');
        $this->handshake($connection);
        $this->transport->message($connection, json_encode(['act' => 'sub', 'ch' => '']));

        // An empty channel is the keepalive frame, not a subscription, and acknowledging it would
        // tell a client its channel is live when nothing was subscribed.
        $this->assertSame([], $this->transport->decoded());
    }

    /**
     * Only what the application published, without the protocol frames the server adds.
     *
     * @return list<array<string, mixed>>
     */
    private function published(): array
    {
        return array_values(array_filter(
            $this->transport->decoded(),
            static fn (array $message): bool => !str_starts_with((string) $message['ev'], 'phue.')
        ));
    }

    /**
     * The same filter over the raw transport, for assertions written against `sent`.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function publishedFrames(): array
    {
        return array_values(array_filter(
            $this->transport->sent,
            static fn(array $frame): bool => !str_contains($frame[1], '"ev":"phue.')
        ));
    }

    public function testAUserTopicIsADifferentTopicFromTheUserStream(): void
    {
        $this->boot();
        $this->module->authenticateWith(static fn(): ?string => 'user:42');

        $scoped = new Connection('fd1');
        $wholeStream = new Connection('fd2');
        $this->handshake($scoped);
        $this->handshake($wholeStream);
        $this->transport->message($scoped, json_encode(['act' => 'sub', 'ch' => 'user:42:orders']));
        $this->transport->message($wholeStream, json_encode(['act' => 'sub', 'ch' => 'user:42']));

        // Conflating these is how a subscriber ends up waiting on a channel nobody publishes
        // to, and it is invisible until a message simply never arrives.
        $this->container->get(Broadcast::class)->for('app')->toUserTopic('42', 'orders', 'scoped');
        $this->container->get(Broadcast::class)->for('app')->toUser('42', 'whole');

        $byConnection = [];

        foreach ($this->transport->sent as [$connectionId, $payload]) {
            $event = ((array) json_decode($payload, true))['ev'];

            // The subscription acknowledgements are protocol frames, not what this test is about.
            if (str_starts_with((string) $event, 'phue.')) {
                continue;
            }

            $byConnection[$connectionId][] = $event;
        }

        // Conflating the two is how a subscriber ends up waiting on a channel nobody publishes
        // to, and it stays invisible until a message simply never arrives.
        $this->assertSame(['scoped'], $byConnection['fd1'] ?? []);
        $this->assertSame(['whole'], $byConnection['fd2'] ?? [], 'a user topic must not reach the whole-stream subscriber');
    }

    public function testUnsubscribingStopsDelivery(): void
    {
        $this->boot();
        $this->module->authenticateWith(static fn(): ?string => 'user:42');

        $connection = new Connection('fd1');
        $this->handshake($connection);
        $this->transport->message($connection, json_encode(['act' => 'sub', 'ch' => 'orders']));
        $this->transport->message($connection, json_encode(['act' => 'unsub', 'ch' => 'orders']));

        $this->container->get(Broadcast::class)->for('app')->to('orders', 'x');

        // Filtered: the subscription was acknowledged before the unsubscribe, and only
        // deliveries say anything about whether the unsubscribe worked.
        $this->assertCount(0, $this->publishedFrames());
    }

    public function testMalformedFramesAreIgnoredRatherThanFatal(): void
    {
        $this->boot();
        $this->module->authenticateWith(static fn(): ?string => 'user:42');

        $connection = new Connection('fd1');
        $this->handshake($connection);

        // A client that sends rubbish should get silence and a chance to correct itself, not a
        // connection it cannot reopen.
        $this->transport->message($connection, 'not json at all');
        $this->transport->message($connection, '{}');
        $this->transport->message($connection, '{"act":""}');

        $this->assertSame([], $this->transport->closed);
        $this->assertSame([], $connection->channels());
    }

    public function testAPingIsAnswered(): void
    {
        $this->boot();
        $this->module->authenticateWith(static fn(): ?string => 'user:42');

        $connection = new Connection('fd1');
        $this->handshake($connection);
        $this->transport->message($connection, json_encode(['act' => 'ping', 'ch' => 'orders']));

        $messages = $this->transport->decoded();

        $this->assertSame('phue.pong', $messages[0]['ev']);
    }

    public function testAClosingSocketIsDetachedFromEveryTopic(): void
    {
        $this->boot();
        $this->module->authenticateWith(static fn(): ?string => 'user:42');

        $connection = new Connection('fd1');
        $this->handshake($connection);
        $this->transport->message($connection, json_encode(['act' => 'sub', 'ch' => 'orders']));
        $this->transport->message($connection, json_encode(['act' => 'sub', 'ch' => 'payments']));

        $this->transport->disconnect($connection);

        $this->transport->sent = [];
        $this->container->get(Broadcast::class)->for('app')->to('orders', 'x');

        // Leaving the subscriptions behind is how a registry grows without bound.
        $this->assertCount(0, $this->transport->sent);
        $this->assertSame([], $this->registry?->channels());
    }

    public function testAFailedSendDoesNotAdvanceTheClientsCursor(): void
    {
        $this->boot();
        $this->module->authenticateWith(static fn(): ?string => 'user:42');

        $connection = new Connection('fd1');
        $this->handshake($connection);
        $this->transport->message($connection, json_encode(['act' => 'sub', 'ch' => 'orders']));

        $this->transport->failing['fd1'] = true;
        $this->container->get(Broadcast::class)->for('app')->to('orders', 'missed');

        $this->assertSame(0, $connection->lastSeen());
    }

    // ----------------------------------------------------------------------- config

    public function testTheModuleIsOffUnlessTurnedOn(): void
    {
        $this->boot([], false, false);

        // The config is still readable when the module is off; the registry is not bound,
        // because a deployment without realtime channels has nothing to register.
        $this->assertFalse(
            $this->container->get(WebsocketConfig::class)->enabled,
            'realtime channels must not be on in a deployed site by default'
        );
        // has() answers "could this be resolved", which autowiring makes true for any class,
        // so the meaningful check is that nothing was published for the server to pick up.
        $this->assertFalse(
            $this->container->has('phue.server.attachers'),
            'an inactive module must not ask the server to open realtime channels'
        );
    }

    public function testDebugTurnsTheModuleOn(): void
    {
        // No `enabled` key at all, so this only passes if the debug flag is really the default.
        $this->boot(debug: true, enabled: null);

        $this->assertTrue($this->container->get(WebsocketConfig::class)->enabled);
    }

    public function testTheTransportPublishesItselfForTheServerToPickUp(): void
    {
        $this->boot();

        // The server has to be told WebSocket is on and has to be handed the handlers, and the
        // server has no idea this module exists.
        $this->assertTrue($this->container->has('phue.server.attachers'));

        $attachers = (array) $this->container->get('phue.server.attachers');
        $this->assertNotSame([], $attachers);

        $server = new \stdClass();
        $attachers[0]($server);

        $this->assertSame($server, $this->transport->attached);
    }
}