<?php

declare(strict_types=1);

/**
 * A real Swoole WebSocket server, booted in its own process by an end-to-end test.
 *
 * Not a stub and not a test double: the actual `Swoole\WebSocket\Server`, the actual module, the
 * actual Redis stream, and the actual fork of the worker processes. Everything the framework claims
 * about more than one worker is a claim about this file — a bus object in one process, however
 * convincing its assertions, cannot fork.
 *
 * The wiring is the module's own: the transport is attached from `phue.server.attachers` and the
 * pumps are started from `phue.server.onWorkerStart`, exactly as `SwooleServeCommand` does it. A
 * harness that called the pump directly would prove the pump works, not that it is ever started.
 *
 * Two routes exist only for the test to drive the server: a health check, and a publish. The publish
 * bypasses the application's HTTP pipeline — this file is about the realtime path, and a route in
 * App/ would make every application change to this suite.
 *
 * Usage: php websocket_server.php <port> <workers> <configDir> <endpointName> [warmBus]
 *
 * @internal Run by \Phue\Tests\Websocket\CrossWorkerDeliveryTest. Not a test itself.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Phue\Core\Config;
use Phue\Core\Container;
use Phue\Core\EventDispatcher;
use Phue\Core\Logger;
use Phue\Core\Routing\Router;
use Phue\Modules\Websocket\Broadcast;
use Phue\Modules\Websocket\WebsocketModule;
use Psr\Log\LoggerInterface;

$port = (int) ($argv[1] ?? 0);
$workers = (int) ($argv[2] ?? 4);
$configDir = (string) ($argv[3] ?? '');
$endpoint = (string) ($argv[4] ?? 'app');
$warmBus = ($argv[5] ?? '') === 'warm';

if ($port < 1 || $configDir === '' || $endpoint === '') {
    fwrite(STDERR, "Usage: websocket_server.php <port> <workers> <configDir> <endpointName> [warm]\n");
    exit(1);
}

$container = new Container();

$container->singleton(Config::class, static fn(): Config => new Config($configDir));

// A file the test can quote back in a failure, because "no message arrived" and "the worker logged
// why" are very different debugging sessions.
$container->singleton(
    LoggerInterface::class,
    static fn(): LoggerInterface => new Logger($configDir . '/server.log')
);

$module = new WebsocketModule();
$module->setDependencies(new Router(), $container, new EventDispatcher());
$module->init();

// An authenticator, because the endpoint below requires one. The module refuses `auth => false`
// outside debug mode and this harness does not want debug mode — a server started in debug would be a
// different server, and the assertions are about the production posture.
$module->authenticateWith(static fn(): ?string => 'e2e-test');

// The accident this file can reproduce on purpose.
//
// Swoole forks its workers from the memory image the master has built by the time start() is called,
// so anything resolved before that point reaches every worker as an object belonging to a process
// that no longer exists there. Nothing in the module's own wiring resolves the bus this early — a
// connection cannot arrive before the fork — which is exactly why a warmup, a config check or an
// eager publish would have gone unnoticed: one bus in the master, inherited by all of them, every
// message carrying an identity all of them claim.
if ($warmBus) {
    $container->get(Broadcast::class)->for($endpoint)->registry();
}

$server = new Swoole\WebSocket\Server('127.0.0.1', $port);

// set() rather than a third constructor argument: Swoole's third parameter is the process mode
// (SWOOLE_BASE or SWOOLE_PROCESS), not a settings array, and passing one there is a TypeError rather
// than a misconfiguration.
$server->set([
    'worker_num' => $workers,
    'log_level' => SWOOLE_LOG_WARNING,
    'heartbeat_check_interval' => 0,
]);

/**
 * @param Swoole\Http\Request $request
 * @param Swoole\Http\Response $response
 */
$server->on('request', static function ($request, $response) use ($container, $endpoint): void {
    $path = (string) ($request->server['request_uri'] ?? '/');
    parse_str((string) ($request->server['query_string'] ?? ''), $query);

    if ($path === '/__control__/health') {
        $response->end('ok');

        return;
    }

    if ($path === '/__control__/publish') {
        $cursor = $container->get(Broadcast::class)->for($endpoint)->to(
            (string) ($query['ch'] ?? ''),
            (string) ($query['ev'] ?? 'test.published'),
            ['n' => (int) ($query['n'] ?? 0)]
        );

        $response->header('Content-Type', 'application/json');
        $response->end((string) json_encode(['cursor' => $cursor]));

        return;
    }

    $response->status(404);
    $response->end('no');
});

foreach ((array) $container->get('phue.server.attachers') as $attacher) {
    $attacher($server);
}

// Nothing else registers 'open', 'message' or 'close'. Swoole keeps one handler per event and a
// later registration replaces the earlier one — the same trap WorkerStart has — so a harness that
// wanted to watch connections could only do it by taking the transport's handler away from it. The
// framework offers no seam for observing a connection, and that is worth knowing; this file
// therefore proves what it needs to prove without one.

$onWorkerStart = $container->has('phue.server.onWorkerStart')
    ? (array) $container->get('phue.server.onWorkerStart')
    : [];

// The one WorkerStart registration, and it calls everything collected under the key. Swoole keeps a
// single handler for this event, so registering a second would cost the first its place — which is
// how a module's startup work disappears without a word.
$server->on('WorkerStart', static function (Swoole\WebSocket\Server $server, int $workerId) use ($onWorkerStart): void {
    foreach ($onWorkerStart as $callback) {
        $callback($workerId);
    }
});


// Printed once the workers are up, so the test does not spend its timeout guessing.
fwrite(STDOUT, "listening on {$port} with {$workers} workers\n");

$server->start();