<?php

declare(strict_types=1);

namespace Phue\Tests\Websocket;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Modules\Websocket\SwooleWebSocketTransport;
use Phue\Tests\Support\RedisTestConnection;
use Phue\Tests\Support\WebSocketClient;

/**
 * The claim that cannot be tested with a bus in one process: it reaches other processes.
 *
 * Everything here runs against real `Swoole\WebSocket\Server` processes with real worker forks, a
 * real Redis stream, the real transport and the real pump. The servers are the ones in
 * tests/Support/websocket_server.php, each booted in a process of its own, because a Swoole server
 * owns the event loop of the process that starts it — a suite that let it own this one would stop
 * being able to run tests at all.
 *
 * Two shapes, on purpose:
 *
 * - one server with two workers, which is the deployment, and which the warm-up case below breaks on
 *   purpose — the bus resolved in the master so that every worker inherits it;
 * - two servers with one worker each, sharing one broker, which is the only way to *prove* two
 *   different processes are involved without asking Swoole which worker took which connection. That
 *   question has no answer this suite can reach: Swoole keeps one handler per event, so hooking
 *   'open' would take the transport's handler away from it, and a worker's registry of its own
 *   connections is process-local by design.
 *
 * Why a hand-written client: PHP has no WebSocket client, and this needs real sockets held open
 * across real processes. See {@see \Phue\Tests\Support\WebSocketClient}.
 *
 * @group integration
 */
#[Group('integration')]
final class CrossWorkerDeliveryTest extends TestCase
{
    private ?RedisTestConnection $redis = null;

    /** @var list<WebSocketClient> */
    private array $clients = [];

    /** @var list<array{process: resource, port: int}> */
    private array $servers = [];

    private string $configDir = '';

    private string $endpoint = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('No Swoole, so no workers, so nothing here would mean anything.');
        }

        $this->redis = RedisTestConnection::available();

        if ($this->redis === null) {
            $this->markTestSkipped('No Redis available; set PHUE_TEST_REDIS_HOST to run this suite.');
        }

        $this->configDir = sys_get_temp_dir() . '/phue_ws_e2e_' . bin2hex(random_bytes(6));
        mkdir($this->configDir, 0777, true);

        // One endpoint per run, named uniquely. The broker namespace is derived from the endpoint
        // name, so this is what keeps the test's messages off a developer's running dev server — and
        // keeps a real dev server's browsers from being handed this suite's events.
        $this->endpoint = 'e2e' . bin2hex(random_bytes(6));

        [$host, $redisPort] = RedisTestConnection::endpoint();

        file_put_contents($this->configDir . '/app.php', '<?php return ["debug" => false];');
        file_put_contents($this->configDir . '/websocket.php', sprintf(
            '<?php return %s;',
            var_export([
                'enabled' => true,
                'transport' => SwooleWebSocketTransport::class,
                'redis' => [
                    'enabled' => true,
                    'host' => $host,
                    'port' => $redisPort,
                    'timeout' => 0.5,
                ],
                'websockets' => [
                    $this->endpoint => [
                        'endpoint' => '/ws',
                        // The production posture, not a shortcut past it: the endpoint authenticates
                        // and allowlists origins, and the harness satisfies both — it installs an
                        // authenticator and the client sends the Origin below. Turning either off is
                        // refused outside debug mode, and a suite that ran in debug mode would be
                        // testing a configuration nobody ships.
                        'auth' => true,
                        'allowed_origins' => ['http://localhost'],
                        'default_retention' => 50,
                    ],
                ],
            ], true)
        ));
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }

        $this->clients = [];

        foreach ($this->servers as $server) {
            // The workers are children of the process that started them, and a Swoole server does not
            // take them with it when the parent is signalled. Ask nicely, then insist.
            @proc_terminate($server['process'], 15);
        }

        usleep(400000);

        foreach ($this->servers as $server) {
            if ((proc_get_status($server['process'])['running'] ?? false) === true) {
                @proc_terminate($server['process'], 9);
            }

            @proc_close($server['process']);

            foreach ($server['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
        }

        $this->servers = [];

        $this->redis?->cleanupFor('phue:ws:' . $this->endpoint);
        $this->redis?->cleanup();

        foreach (glob($this->configDir . '/*') ?: [] as $file) {
            @unlink((string) $file);
        }

        @rmdir($this->configDir);

        parent::tearDown();

        $this->redis = null;
    }

    // ------------------------------------------------------------------ the fixture, asserted

    public function testTheServerIsUpWithABrokerItCanReach(): void
    {
        $port = $this->bootServer(workers: 2);

        $body = @file_get_contents(
            sprintf('http://127.0.0.1:%d/__control__/health', $port),
            false,
            stream_context_create(['http' => ['timeout' => 5.0]])
        );

        $this->assertSame('ok', $body, 'The server is not answering HTTP.' . $this->serverLog());
    }

    // ------------------------------------------------------------------ one server, several workers

    public function testAMessageReachesEveryClientConnectedToTheServer(): void
    {
        $port = $this->bootServer(workers: 2);

        $clients = [$this->subscriber($port), $this->subscriber($port), $this->subscriber($port)];

        $cursor = $this->publish($port, 'orders', 'order.paid', 7);

        foreach ($clients as $index => $client) {
            $payload = $this->received($client, 'order.paid');

            $this->assertNotNull($payload, 'Client ' . $index . ' received nothing.' . $this->serverLog());
            $this->assertSame('orders', $payload['ch']);
            $this->assertSame(7, $payload['d']['n'] ?? null);
            $this->assertSame($cursor, $payload['id'] ?? null, 'The envelope must carry the cursor the publish was given.');
        }
    }

    /**
     * The regression gate for the fork the server itself performs.
     *
     * Swoole forks its workers from the memory the master has built. Nothing in the module's wiring
     * resolves the bus before that point — a connection cannot arrive earlier — which is exactly why
     * this case is dangerous: one warmup, one eager config check, and every worker inherits the
     * master's bus, with the master's connection and the master's identity on it. Every message on
     * the stream then carries an origin that all of them claim, every pump discards all of them, and
     * nothing anywhere says why.
     *
     * The harness is told to resolve the bus before `start()` on purpose.
     */
    public function testDeliverySurvivesTheBusHavingBeenBuiltBeforeTheWorkersForked(): void
    {
        $port = $this->bootServer(workers: 2, warmBus: true);

        $clients = [$this->subscriber($port), $this->subscriber($port)];

        $this->publish($port, 'orders', 'order.paid', 11);

        foreach ($clients as $index => $client) {
            $payload = $this->received($client, 'order.paid');

            $this->assertNotNull(
                $payload,
                sprintf(
                    'Client %d received nothing, on a server whose bus was built before its workers '
                    . 'forked. Every worker is holding the master\'s bus and every message on the '
                    . 'stream looks like its own work, so cross-worker delivery is off silently.%s',
                    $index,
                    $this->serverLog()
                )
            );

            $this->assertSame(11, $payload['d']['n'] ?? null);
        }
    }

    public function testNothingIsDeliveredTwiceToTheWorkerThatPublished(): void
    {
        $port = $this->bootServer(workers: 2);

        $client = $this->subscriber($port);

        $this->publish($port, 'orders', 'order.paid', 3);

        // Counted rather than awaited: a second delivery arrives immediately after the first, and a
        // test that only waits for the first cannot tell the difference.
        $seen = 0;
        $deadline = microtime(true) + 1.5;

        while (microtime(true) < $deadline) {
            $frame = $client->receive(0.2);

            if ($frame !== null && str_contains($frame['payload'], 'order.paid')) {
                $seen++;
            }
        }

        $this->assertSame(1, $seen, 'A message reached the publishing worker\'s own subscriber twice.');
    }

    // ------------------------------------------------------------------ two servers, two processes

    public function testAClientOnOneProcessReceivesWhatAnotherProcessPublished(): void
    {
        $publishing = $this->bootServer(workers: 1);
        $listening = $this->bootServer(workers: 1);

        // Two processes, provably: two servers, two ports, two master processes, each with one worker.
        // Nothing here depends on which worker Swoole hands a connection to.
        $onPublisher = $this->subscriber($publishing);
        $onOther = $this->subscriber($listening);

        $this->publish($publishing, 'orders', 'order.paid', 5);

        foreach (['the publishing process' => $onPublisher, 'the other process' => $onOther] as $name => $client) {
            $payload = $this->received($client, 'order.paid');

            $this->assertNotNull(
                $payload,
                sprintf('A subscriber on %s received nothing published by the other.%s', $name, $this->serverLog())
            );
        }
    }

    public function testAClientThatConnectsLaterIsHandedWhatItMissed(): void
    {
        $port = $this->bootServer(workers: 1);

        $subscriber = $this->subscriber($port);

        $this->publish($port, 'orders', 'order.paid', 1);

        $this->assertNotNull(
            $this->received($subscriber, 'order.paid'),
            'The subscriber received nothing at all.' . $this->serverLog()
        );

        // A client that connects after the fact and asks from the beginning. Retention has to mean
        // the same thing in every process, or a reconnecting client is told it missed messages it
        // received.
        $late = $this->subscriber($port);

        $payload = $this->received($late, 'order.paid');

        $this->assertNotNull($payload, 'A client that asked for its missed messages was given none.' . $this->serverLog());
        $this->assertSame(1, $payload['d']['n'] ?? null);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Connect, subscribe, and confirm the subscription landed.
     *
     * Waiting for the acknowledgement rather than for a message is what makes this useful: the socket
     * being open is not evidence the channel works, and a module that accepted the connection and
     * then said nothing would otherwise pass as a quiet channel.
     */
    private function subscriber(int $port): WebSocketClient
    {
        $client = WebSocketClient::connect('127.0.0.1', $port, '/ws', ['Origin' => 'http://localhost']);

        $this->clients[] = $client;

        $client->send(['act' => 'sub', 'ch' => 'orders', 'since' => 0]);

        // Read by hand rather than through await(): the replay of what this client missed arrives
        // *before* the acknowledgement, so a helper that walked over it looking for the
        // acknowledgement would have thrown away the very thing the next assertion is about.
        $walkedOver = [];
        $ack = null;
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $frame = $client->receive(max(0.05, $deadline - microtime(true)));

            if ($frame === null) {
                continue;
            }

            if (str_contains($frame['payload'], 'phue.subscribed')) {
                $ack = $frame;

                break;
            }

            $walkedOver[] = $frame;
        }

        $this->assertNotNull($ack, 'A subscription was never acknowledged.' . $this->serverLog());

        $client->pushback($walkedOver);

        return $client;
    }

    /**
     * The decoded envelope of the first frame carrying $event, or null if none arrived.
     *
     * @return array<string, mixed>|null
     */
    private function received(WebSocketClient $client, string $event): ?array
    {
        $frame = $client->await(
            static fn(array $f): bool => str_contains($f['payload'], '"ev":"' . $event . '"'),
            5.0
        );

        if ($frame === null) {
            return null;
        }

        $decoded = json_decode($frame['payload'], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Publish over a server's own HTTP, which is how an application does it.
     */
    private function publish(int $port, string $topic, string $event, int $n): int
    {
        $url = sprintf(
            'http://127.0.0.1:%d/__control__/publish?ch=%s&ev=%s&n=%d',
            $port,
            urlencode($topic),
            urlencode($event),
            $n
        );

        $body = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => 5.0, 'ignore_errors' => true],
        ]));

        $decoded = json_decode((string) $body, true);

        $this->assertIsArray($decoded, 'The publish never happened: ' . var_export($body, true) . $this->serverLog());

        return (int) ($decoded['cursor'] ?? 0);
    }

    /**
     * Start a server and wait until it says it is listening.
     *
     * @param bool $warmBus Resolve the bus before `start()`, so the workers inherit it.
     *
     * @return int The port it is listening on.
     */
    private function bootServer(int $workers = 2, bool $warmBus = false): int
    {
        $port = $this->freePort();

        // An array, not a command string, and that is not a style choice: with a string, proc_open
        // runs it through a shell and hands back a handle to *the shell*. Signalling that shell kills
        // nothing, so every test left a master and its workers running — a hundred idle servers all
        // polling the same Redis by the end of a suite, which is how a leak turns into a machine that
        // cannot run its own tests.
        $process = proc_open([
            PHP_BINARY,
            dirname(__DIR__) . '/Support/websocket_server.php',
            (string) $port,
            (string) $workers,
            $this->configDir,
            $this->endpoint,
            $warmBus ? 'warm' : 'cold',
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            $this->fail('The WebSocket server process would not start.');
        }

        $this->servers[] = ['process' => $process, 'port' => $port, 'pipes' => $pipes];

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + 10.0;
        $errors = '';

        while (microtime(true) < $deadline) {
            if (str_contains((string) stream_get_contents($pipes[1]), 'listening')) {
                // Left open on purpose: the child writes to it for as long as it runs, and a closed
                // pipe is a broken-pipe error in a worker whose output nobody is reading.
                return $port;
            }

            $errors = (string) stream_get_contents($pipes[2]);

            usleep(50000);
        }

        // Whatever the child said, including a fatal it wrote before it had a chance to say anything
        // useful. A harness that fails with "never reported listening" and nothing else costs the
        // next person the ten minutes it takes to find that line.
        $this->fail(sprintf(
            "The WebSocket server on port %d never reported that it was listening.\n--- server output ---\n%s%s",
            $port,
            $errors,
            $this->serverLog()
        ));
    }

    /**
     * A port nothing is listening on.
     */
    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if (!is_resource($socket)) {
            $this->fail(sprintf('Could not reserve a port: %s (%d)', $errstr, $errno));
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * Whatever the workers said, for a failure message.
     */
    private function serverLog(): string
    {
        $log = @file_get_contents($this->configDir . '/server.log');

        return ($log === false || $log === '') ? '' : "\n--- server log ---\n" . $log;
    }
}