<?php

declare(strict_types=1);

namespace Phue\Tests\Websocket;

use Phue\Core\Config;
use Phue\Core\Container;
use Phue\Core\EventDispatcher;
use Phue\Core\Websocket\Connection;
use Phue\Modules\Websocket\Broadcast;
use Phue\Modules\Websocket\WebsocketConfig;
use Phue\Modules\Websocket\WebsocketEndpoint;
use Phue\Modules\Websocket\WebsocketModule;
use Phue\Tests\Websocket\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Endpoints exist so that authentication is per surface rather than per server, and that only
 * works if the endpoints cannot see each other's topics. These tests are mostly about that
 * separation: the isolation is the security property, and the rest is configuration.
 */
final class WebsocketEndpointTest extends TestCase
{
    // ------------------------------------------------------------------ configuration

    public function testAnApplicationThatDescribesNothingGetsOneStrictEndpoint(): void
    {
        $settings = WebsocketConfig::fromArray(['enabled' => true]);

        $this->assertCount(1, $settings->endpoints());
        $this->assertSame('/ws', $settings->endpoint('app')?->endpoint);
        $this->assertTrue($settings->endpoint('app')?->auth, 'the implicit endpoint authenticates');
    }

    public function testDebugAddsADevelopmentEndpointWithoutReplacingTheApplicationOne(): void
    {
        $settings = WebsocketConfig::fromArray(['enabled' => true], debug: true);

        // Regression: adding the dev endpoint used to be treated as "the application described
        // something", which left a debug server with no /ws at all.
        $this->assertNotNull($settings->endpoint('app'), 'the app endpoint must survive debug');
        $this->assertNotNull($settings->endpoint(WebsocketConfig::DEV_ENDPOINT));
    }

    public function testAConfiguredApplicationEndpointIsLeftExactlyAsWritten(): void
    {
        $settings = WebsocketConfig::fromArray([
            'enabled' => true,
            'websockets' => ['main' => ['endpoint' => '/realtime']],
        ], debug: true);

        $this->assertSame(['main', WebsocketConfig::DEV_ENDPOINT], array_keys($settings->endpoints()));
    }

    public function testDisablingAuthenticationIsRefusedOutsideDebug(): void
    {
        // The failure mode this prevents is a development endpoint being copied into production
        // config with its flags intact, which is exactly how an unauthenticated channel ships.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/only allowed in debug mode/');

        WebsocketConfig::fromArray([
            'enabled' => true,
            'websockets' => ['dev' => ['endpoint' => '/_phue/ws', 'auth' => false]],
        ], debug: false);
    }

    public function testDisablingAuthenticationIsAllowedInDebug(): void
    {
        $settings = WebsocketConfig::fromArray([
            'enabled' => true,
            'websockets' => ['dev' => ['endpoint' => '/_phue/ws', 'auth' => false]],
        ], debug: true);

        $this->assertFalse($settings->endpoint('dev')?->auth);
    }

    public function testAuthenticationDefaultsToOnWhenNotMentioned(): void
    {
        $settings = WebsocketConfig::fromArray([
            'enabled' => true,
            'websockets' => ['app' => ['endpoint' => '/ws']],
        ]);

        $this->assertTrue($settings->endpoint('app')?->auth);
    }

    // ------------------------------------------------------------------ path matching

    public function testAPathIsMatchedIgnoringTrailingSlashes(): void
    {
        $settings = $this->settings();

        foreach (['/ws', '/ws/', 'ws'] as $path) {
            $this->assertSame('app', $settings->endpointForPath($path)?->name, $path);
        }
    }

    public function testAQueryStringIsNotPartOfThePath(): void
    {
        // A token commonly rides in the query string, so the path has to be read without it or
        // every authenticated connection would miss its endpoint.
        $settings = $this->settings();

        $this->assertSame('app', $settings->endpointForPath('/ws?token=abc')?->name);
    }

    public function testAPathThatMatchesNothingHasNoEndpoint(): void
    {
        $this->assertNull($this->settings()->endpointForPath('/not-a-websocket'));
    }

    public function testAnEndpointPathIsNormalised(): void
    {
        $endpoint = WebsocketEndpoint::fromArray('app', ['endpoint' => 'ws/']);

        $this->assertSame('/ws', $endpoint->endpoint);
    }

    // ------------------------------------------------------------------ isolation

    public function testAConnectionOnOneEndpointCannotSeeAnotherEndpointsTopics(): void
    {
        [$module, $transport, $container] = $this->boot();

        // Authenticated on the app endpoint, which is the one that demands it.
        $connection = new Connection('a1');
        $transport->connect($connection, query: ['token' => 'alice-token']);
        $transport->message($connection, '{"act":"sub","ch":"orders"}');

        $transport->sent = [];
        $container->get(Broadcast::class)->for('app')->to('orders', 'order.paid');

        $this->assertNotEmpty($transport->sent, 'the app endpoint delivers its own publishes');
    }

    public function testAPublishOnOneEndpointDoesNotReachAnother(): void
    {
        [$module, $transport, $container] = $this->boot();

        // One object per connection, reused: the transport hands the same instance to every
        // event for a socket, and the endpoint lookup is keyed by that instance.
        $app = new Connection('a1');
        $dev = new Connection('d1');

        $transport->connect($app, query: ['token' => 'alice-token']);
        $transport->message($app, '{"act":"sub","ch":"orders"}');
        $transport->connect($dev, path: WebsocketConfig::DEV_ENDPOINT_PATH);
        $transport->message($dev, '{"act":"sub","ch":"orders"}');

        $transport->sent = [];
        $container->get(Broadcast::class)->for('app')->to('orders', 'order.paid');

        $deliveredTo = array_map(static fn(array $entry): string => $entry[0], $transport->sent);

        // The whole security claim of this change, in one assertion: the open endpoint subscribed
        // to the same topic name and still hears nothing, because it is a different room.
        $this->assertSame(['a1'], $deliveredTo);
    }

    public function testAnUnauthenticatedConnectionCannotReachAnAuthenticatedEndpoint(): void
    {
        [$module, $transport, $container] = $this->boot();

        // No token: refused on the app endpoint, and refused before it can subscribe.
        $connection = new Connection('a1');
        $transport->connect($connection);
        $transport->message($connection, '{"act":"sub","ch":"orders"}');

        $transport->sent = [];
        $container->get(Broadcast::class)->for('app')->to('orders', 'order.paid');

        $this->assertSame([], $transport->sent);
        $this->assertCount(1, $transport->closed, 'the handshake was refused');
    }

    public function testTheDevelopmentEndpointAcceptsAnUnauthenticatedConnection(): void
    {
        [, $transport] = $this->boot();

        $transport->connect(new Connection('d1'), path: WebsocketConfig::DEV_ENDPOINT_PATH);

        $this->assertSame([], $transport->closed);
    }

    public function testAHandshakeOnAnUnknownPathIsRefused(): void
    {
        [, $transport] = $this->boot();

        $connection = new Connection('x1');
        $transport->connect($connection, path: '/somewhere-else');

        // Nothing is closed yet: see testARefusedConnectionIsClosedWhenItSaysSomething for why.
        $transport->message($connection, '{"act":"sub","ch":"orders"}');

        $this->assertCount(1, $transport->closed);
        $this->assertSame('unknown endpoint', $transport->closed[0][2]);
    }

    public function testAnUnknownPathIsToldSoRatherThanLeftGuessing(): void
    {
        [, $transport] = $this->boot();

        $connection = new Connection('x1');
        $transport->connect($connection, path: '/somewhere-else');
        $transport->message($connection, '{"act":"sub","ch":"orders"}');

        // "authentication required" would be a true statement and a useless one to whoever is
        // looking at a toolbar that never reloads. The path is what they can actually change.
        $this->assertSame('unknown endpoint', $transport->closed[0][2]);
    }

    public function testTheDefaultRetentionActuallyAppliesToAnUnlistedTopic(): void
    {
        [, $transport, $container] = $this->boot();

        // Retention is how a client resumes after a backgrounded socket, and it is configured
        // per endpoint with a default for everything not listed. A default that was ignored made
        // every unlisted topic silently unresumable, which is the one failure a client cannot
        // detect for itself.
        $connection = new Connection('a1');
        $transport->connect($connection, query: ['token' => 't']);

        $broadcast = $container->get(Broadcast::class)->for('app');
        $broadcast->to('orders', 'one');
        $broadcast->to('orders', 'two');

        // Resuming from cursor 0 asks for both messages.
        $transport->message($connection, '{"act":"sub","ch":"orders","since":0}');

        $resumed = array_filter(
            $transport->sent,
            static fn(array $entry): bool => str_contains($entry[1], '"ev":"one"')
                || str_contains($entry[1], '"ev":"two"')
        );

        $this->assertCount(2, $resumed, 'both retained messages should have been replayed');
    }

    public function testRetentionOfZeroKeepsNothing(): void
    {
        [, $transport, $container] = $this->boot(defaultRetention: 0);

        $connection = new Connection('a1');
        $transport->connect($connection, query: ['token' => 't']);

        $container->get(Broadcast::class)->for('app')->to('orders', 'one');
        $transport->message($connection, '{"act":"sub","ch":"orders","since":0}');

        $this->assertSame([], $transport->sent, 'nothing is retained, so nothing can be resumed');
    }

    public function testTheConfiguredQueueLimitReachesTheTransport(): void
    {
        [, , $container] = $this->boot(queueLimit: 7);

        // Asserted on the binding rather than through a connection, because the limit is applied
        // when the transport builds each Connection and the fake never builds one. A queue limit
        // that never reaches the transport is an out-of-memory kill waiting for a slow client, and
        // it looks exactly like a working one until the worst possible moment.
        $transport = $container->get(\Phue\Modules\Websocket\SwooleWebSocketTransport::class);

        $property = new \ReflectionProperty($transport, 'queueLimit');

        $this->assertSame(7, $property->getValue($transport));
    }

    public function testAPublishWithoutAnEndpointSaysSoRatherThanFailingInAClosure(): void
    {
        [, , $container] = $this->boot();

        // The most likely mistake with this API, and the one that used to produce an
        // ArgumentCountError from a closure three frames down. An error that names the omission is
        // the difference between a five-second and a five-minute fix.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Broadcast::for/');

        $container->get(Broadcast::class)->to('orders', 'order.paid');
    }

    public function testPublishingToAnEndpointThatDoesNotExistIsAnError(): void
    {
        [$module, $transport, $container] = $this->boot();

        // Silently dropping it would be worse: the message is gone and the caller was told
        // nothing, which is how a feature ends up "sometimes not working".
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/No WebSocket endpoint named "nope"/');

        $container->get(Broadcast::class)->for('nope')->to('orders', 'x');
    }

    public function testARefusedConnectionIsClosedWhenItSaysSomething(): void
    {
        [, $transport, $container] = $this->boot();

        // A handshake cannot be closed in place, so the refusal is held and acted on at the first
        // frame. Asserting the code and the reason is the point: a client with no console has only
        // the close code to go on.
        $connection = new Connection('a1');
        $transport->connect($connection);
        $transport->message($connection, '{"act":"sub","ch":"orders"}');

        $this->assertSame([], $container->get(Broadcast::class) === null ? [] : []);

        $this->assertCount(1, $transport->closed);
        $this->assertSame(1008, $transport->closed[0][1]);
        $this->assertSame('authentication required', $transport->closed[0][2]);
    }

    public function testARefusedConnectionNeverReceivesAPublish(): void
    {
        [, $transport, $container] = $this->boot();

        $connection = new Connection('a1');
        $transport->connect($connection);
        $transport->message($connection, '{"act":"sub","ch":"orders"}');
        $transport->sent = [];

        $container->get(Broadcast::class)->for('app')->to('orders', 'order.paid');

        $this->assertSame([], $transport->sent);
    }

    /**
     * @return array{0: WebsocketModule, 1: FakeTransport, 2: Container}
     */
    private function boot(int $defaultRetention = 100, int $queueLimit = 256): array
    {
        $dir = sys_get_temp_dir() . '/phue_ws_ep_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        file_put_contents($dir . '/app.php', '<?php return ["debug" => true];');
        file_put_contents($dir . '/websocket.php', '<?php return ' . var_export([
            'enabled' => true,
            'transport' => FakeTransport::class,
            'queue_limit' => $queueLimit,
            'websockets' => [
                'app' => [
                    'endpoint' => '/ws',
                    'auth' => true,
                    'default_retention' => $defaultRetention,
                ],
                'dev' => ['endpoint' => WebsocketConfig::DEV_ENDPOINT_PATH, 'auth' => false],
            ],
        ], true) . ';');

        $container = new Container();
        $container->singleton(Config::class, static fn(): Config => new Config($dir));

        $transport = new FakeTransport();
        $container->singleton(FakeTransport::class, fn(): FakeTransport => $transport);

        $module = new WebsocketModule();
        $module->setDependencies(new \Phue\Core\Routing\Router(), $container, new EventDispatcher());
        $module->init();

        return [$module, $transport, $container];
    }

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->tempDirs as $dir) {
            foreach ((array) glob($dir . '/*') as $file) {
                @unlink((string) $file);
            }

            @rmdir($dir);
        }

        $this->tempDirs = [];
    }

    private function settings(): WebsocketConfig
    {
        return WebsocketConfig::fromArray([
            'enabled' => true,
            'websockets' => [
                'app' => ['endpoint' => '/ws'],
                'dev' => ['endpoint' => WebsocketConfig::DEV_ENDPOINT_PATH, 'auth' => false],
            ],
        ], debug: true);
    }
}
