<?php

declare(strict_types=1);

namespace Phue\Modules\Server\Commands;

use Phue\Core\Application as PhueApplication;
use Phue\Core\Console\CommandInterface;
use Phue\Core\Request as PhueRequest;
use Phue\Core\Response as PhueResponse;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\WebSocket\Server as SwooleServer;
use Phue\Core\Console\PathResolver;

/**
 * Class SwooleServeCommand
 *
 * CLI Command to boot the memory-resident high-performance Swoole HTTP web server.
 *
 * @package Phue\Core\Console\Commands
 */
final class SwooleServeCommand implements CommandInterface
{
    /**
     * The group this command belongs to.
     *
     * Commands in the same group are listed together in the help output.
     */
    protected string $group = 'Server';
    
    /**
     * Gets the group this command belongs to.
     *
     * @return string Command group name.
     */
    public function getGroup(): string
    {
        return $this->group;
    }

    /**
     * Gets the unique command identifier name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'serve';
    }

    /**
     * Gets a short description of the command for the CLI menu.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Starts the high-performance Swoole HTTP Server.';
    }

    /**
     * Executes the Swoole HTTP server process with optional --host and --port overrides.
     *
     * @param array<int, string> $args Command options (e.g. ['--host=0.0.0.0', '--port=8000']).
     * @param resource $outputStream Standard output stream resource.
     * @param resource $errorStream Standard error stream resource.
     * @return int Status code (0 for success, non-zero for failure).
     */
    public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        if (!extension_loaded('swoole')) {
            fwrite($errorStream, "Error: The Swoole PHP extension is not installed or enabled.\n");
            return 1;
        }

        $host = '0.0.0.0';
        $portStr = '8000';

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--port=')) {
                $portStr = substr($arg, 7);
            } elseif (str_starts_with($arg, '--host=')) {
                $host = substr($arg, 7);
            }
        }

        if (!ctype_digit($portStr)) {
            fwrite($errorStream, sprintf("Error: Invalid port value '%s'. Port must be a positive integer.\n", $portStr));
            return 1;
        }

        $port = (int) $portStr;
        if ($port < 1 || $port > 65535) {
            fwrite($errorStream, sprintf("Error: Port '%d' out of range (1-65535).\n", $port));
            return 1;
        }

        $baseDir = PathResolver::resolveBasePath();

        // Instantiate Application once outside the request loop (Memory-Resident Architecture)
        $app = new PhueApplication($baseDir);

        // 1. Setup Asset Registry
        /** @var \Phue\Core\Asset\AssetRegistry $registry */
        $registry = $app->getContainer()->get(\Phue\Core\Asset\AssetRegistry::class);
        $frontendPath = (string) $app->getConfig()->get('app.frontend_path', $app->getBasePath() . '/App/Frontend');
        if (is_dir($frontendPath)) {
            $registry->registerSource('/assets', $frontendPath);
        }
        $assetsPath = (string) $app->getConfig()->get('app.assets_path', $app->getBasePath() . '/App/Assets');
        if (is_dir($assetsPath)) {
            $registry->registerSource('/assets/app', $assetsPath);
        }
        $vendorAssetsPath = $app->getBasePath() . '/storage/assets/vendor';
        if (is_dir($vendorAssetsPath)) {
            $registry->registerSource('/assets/vendor', $vendorAssetsPath);
        }

        // 2. Scan and Register Controllers
        $controllersPath = (string) $app->getConfig()->get('app.controllers_path', $app->getBasePath() . '/App/Controllers');
        $controllersNamespace = (string) $app->getConfig()->get('app.controllers_namespace', 'App\\Controllers');
        if (is_dir($controllersPath)) {
            $app->getRouter()->scanControllers($controllersPath, $controllersNamespace);
        }


        // Two independent questions, and both answers are needed.
        //
        // `websocket.enabled` says whether the project *asked* for realtime.
        // `phue.websocket.active` says whether the capability is actually wired:
        // the Websocket module binds that key, and nothing else does. Reading the
        // config alone cannot answer it — config/websocket.php describes the
        // module, and the env var behind it lives in .env.example, which every
        // project receives whether or not the module is installed. So a project
        // with APP_WEBSOCKET=true and no Websocket module reports "enabled" for
        // something that does not exist, and that is what made this server start
        // as a WebSocket server and die on Swoole's "require onMessage callback".
        //
        // The container key is used rather than Container::has(SomeClass::class):
        // has() answers true for any autowireable class, which is a question
        // about PHP's reflection and not about what this application installed.
        // WebsocketModule registers this key for exactly this question.
        $websocketWanted = (bool) $app->getConfig()->get('websocket.enabled', false);
        $websocketPresent = $app->getContainer()->has('phue.websocket.active');

        if ($websocketWanted && !$websocketPresent) {
            fwrite(
                $outputStream,
                "  websocket is enabled in config but the Websocket module is not installed." .
                " Serving HTTP only. Add it with: php phue module:new websocket" . PHP_EOL
            );
        }

        $websocketEnabled = $websocketWanted && $websocketPresent;

        try {
            $server = $websocketEnabled
                ? new SwooleServer($host, $port)
                : new \Swoole\Http\Server($host, $port);

            $server->set([
                'document_root' => $baseDir . '/public',
                'enable_static_handler' => true
            ]);

            // Swoole\WebSocket\Server::start() throws "require onMessage callback"
            // unless one is registered; Swoole\Http\Server needs none. The no-op
            // is therefore registered for the WebSocket server only, and the
            // Websocket module replaces it when it attaches its own handler.
            if ($websocketEnabled) {
                $server->on('message', static function (): void {
                });
            }

            // Realtime-capable modules attach here, and this command does not know which ones
            // exist: the Websocket module pushes a closure that hooks the server's open, message
            // and close events. The alternative — a module reaching into the server itself —
            // would mean the server owning a concept it has no use for.
            $container = $app->getContainer();

            // One key. Worker startup is not among these: Swoole holds a single WorkerStart
            // handler, so those callbacks are collected under their own key above and dispatched
            // from the one registration there, rather than being free to overwrite each other.
            foreach (['phue.server.attachers'] as $key) {
                if (!$container->has($key)) {
                    continue;
                }

                /** @var array<int, callable> $callbacks */
                $callbacks = (array) $container->get($key);

                foreach ($callbacks as $callback) {
                    $callback($server);
                }
            }
        } catch (\Throwable $e) {
            fwrite($errorStream, sprintf("Error: Failed to bind Swoole server to %s:%d - %s\n", $host, $port, $e->getMessage()));
            return 1;
        }

        fwrite($outputStream, sprintf("\033[32mPhue Swoole HTTP Server started:\033[0m http://%s:%d\n", $host, $port));
        fwrite($outputStream, "Press Ctrl+C to stop the server.\n\n");

        // The one WorkerStart handler, owned here on purpose.
        //
        // Swoole keeps a single WorkerStart handler: registering another replaces the first, and
        // nothing says so. Two modules each registering their own meant one of them silently never
        // ran — which is how live reload stopped working under Swoole while every request answered
        // normally, and which of the two lost depended on the order the modules initialised in.
        //
        // So the server registers once and calls everything collected under the key. A module that
        // wants startup work appends to the list and cannot take another's away.
        $onWorkerStart = [];

        if ($container->has('phue.server.onWorkerStart')) {
            $onWorkerStart = (array) $container->get('phue.server.onWorkerStart');
        }

        $server->on('WorkerStart', static function ($server, $workerId) use ($onWorkerStart): void {
            foreach ($onWorkerStart as $callback) {
                $callback($workerId);
            }
        });

        $server->on('request', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) use ($app): void {
            // A WebSocket handshake arrives here first and then becomes a connection, not a
            // response. Running it through the HTTP pipeline as well is what produced the
            // "session_start(): headers already sent" flood: Swoole has answered the upgrade
            // by the time anything in the pipeline writes, and every auth touchpoint in there
            // tried to start a session against a finished response.
            if ($this->isWebSocketUpgrade($swooleRequest)) {
                return;
            }

            $phueRequest = $this->transformRequest($swooleRequest);
            $phueResponse = $app->handle($phueRequest);

            $this->emitResponse($phueResponse, $swooleResponse);
        });

        $server->start();

        return 0;
    }

    /**
     * Whether this request is a WebSocket handshake rather than an HTTP request.
     *
     * @param SwooleRequest $request
     * @return bool
     */
    private function isWebSocketUpgrade(SwooleRequest $request): bool
    {
        $upgrade = strtolower((string) ($request->header['upgrade'] ?? $request->server['http_upgrade'] ?? ''));

        return $upgrade === 'websocket';
    }

    /**
     * Converts an incoming Swoole HTTP Request into a framework-native Phue Request.
     *
     * @param SwooleRequest $swooleRequest Native Swoole request object.
     * @return PhueRequest Populated Phue request representation.
     */
    private function transformRequest(SwooleRequest $swooleRequest): PhueRequest
    {
        $server = [];
        if (isset($swooleRequest->server) && is_array($swooleRequest->server)) {
            foreach ($swooleRequest->server as $key => $value) {
                $server[strtoupper((string) $key)] = (string) $value;
            }
        }

        $headers = [];
        if (isset($swooleRequest->header) && is_array($swooleRequest->header)) {
            foreach ($swooleRequest->header as $key => $value) {
                $normalizedKey = ucwords(strtolower((string) $key), '-');
                $headers[$normalizedKey] = (string) $value;
                $server['HTTP_' . strtoupper(str_replace('-', '_', $normalizedKey))] = (string) $value;
            }
        }

        $get = isset($swooleRequest->get) && is_array($swooleRequest->get) ? $swooleRequest->get : [];
        $post = isset($swooleRequest->post) && is_array($swooleRequest->post) ? $swooleRequest->post : [];
        $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = $swooleRequest->getContent();
            if ($raw !== '' && $raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $post = $decoded;
                }
            }
        }
        $cookie = isset($swooleRequest->cookie) && is_array($swooleRequest->cookie) ? $swooleRequest->cookie : [];
        $files = isset($swooleRequest->files) && is_array($swooleRequest->files) ? $swooleRequest->files : [];

        $method = $server['REQUEST_METHOD'] ?? 'GET';
        $uri = $server['REQUEST_URI'] ?? '/';
        $content = $swooleRequest->getContent() ?: null;

        return new PhueRequest(
            method: $method,
            uri: $uri,
            queryParams: $get,
            parsedBody: $post,
            headers: $headers,
            serverParams: $server,
            cookies: $cookie
        );

    }

    /**
     * Emits the framework Phue Response to the client via Swoole Response emitter.
     *
     * @param PhueResponse $phueResponse Evaluated framework response.
     * @param SwooleResponse $swooleResponse Active Swoole response handler.
     * @return void
     */
    private function emitResponse(PhueResponse $phueResponse, SwooleResponse $swooleResponse): void
    {
        $swooleResponse->status($phueResponse->getStatusCode());

        foreach ($phueResponse->getHeaders() as $name => $values) {
            if (strtolower((string) $name) === 'content-length') {
                continue;
            }
            foreach ((array) $values as $value) {
                $swooleResponse->header((string) $name, (string) $value);
            }
        }

        // Cookies live outside Response::getHeaders() by design, so they are emitted here.
        foreach ($phueResponse->getCookies() as $cookie) {
            $swooleResponse->header('Set-Cookie', $cookie->toHeaderString());
        }

        $swooleResponse->end($phueResponse->getContent());
    }
}
