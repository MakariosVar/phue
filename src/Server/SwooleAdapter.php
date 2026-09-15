<?php

declare(strict_types=1);

namespace Phue\Server;

use Phue\Core\Application;
use Phue\Core\Container;
use Phue\Core\Request;
use Phue\Core\Response;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server as SwooleHttpServer;

/**
 * Async Coroutine-based Swoole HTTP Server Adapter.
 *
 * @package Phue\Server
 */
final class SwooleAdapter implements ServerAdapterInterface
{
    private ?Application $app = null;
    private ?Container $container = null;
    private ?SwooleHttpServer $server = null;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 9501,
        private readonly array $settings = [
            'worker_num' => 4,
            'enable_coroutine' => true,
        ]
    ) {}

    public function boot(Application $app): void
    {
        $this->app = $app;
        $this->container = $app->getContainer();
    }

    public function handleRequest(Request $request): Response
    {
        if ($this->app === null) {
            throw new \RuntimeException("Swoole adapter is not booted with an Application instance.");
        }

        return $this->app->handle($request);
    }

    public function run(): void
    {
        if ($this->app === null) {
            throw new \RuntimeException("Swoole adapter must be booted before running.");
        }

        $this->server = new SwooleHttpServer($this->host, $this->port);
        $this->server->set($this->settings);

        $this->server->on('request', function (SwooleRequest $swRequest, SwooleResponse $swResponse) {
            try {
                // 1. Convert Swoole Request to Phue Request
                $phueRequest = $this->transformSwooleRequest($swRequest);

                // 2. Process via Phue Application Kernel
                $phueResponse = $this->handleRequest($phueRequest);

                // 3. Emit headers and status code to Swoole Response
                $swResponse->status($phueResponse->getStatusCode());
                foreach ($phueResponse->getHeaders() as $name => $value) {
                    $swResponse->header($name, (string) $value);
                }

                // 4. Send payload
                $swResponse->end($phueResponse->getContent());
            } catch (\Throwable $e) {
                $swResponse->status(500);
                $swResponse->end('Internal Server Error: ' . $e->getMessage());
            }
        });

        $this->server->start();
    }

    public function shutdown(): void
    {
        $this->server?->shutdown();
    }

    /**
     * Converts Swoole Request object to native Phue Request.
     */
    private function transformSwooleRequest(SwooleRequest $swRequest): Request
    {
        $headers = [];
        foreach ($swRequest->header ?? [] as $key => $val) {
            $normalizedKey = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', (string) $key))));
            $headers[$normalizedKey] = (string) $val;
        }

        $post = $swRequest->post ?? [];
        $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = $swRequest->getContent();
            if ($raw !== '' && $raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $post = $decoded;
                }
            }
        }

        return new Request(
            uri: $swRequest->server['request_uri'] ?? '/',
            method: $swRequest->server['request_method'] ?? 'GET',
            queryParams: $swRequest->get ?? [],
            parsedBody: $post,
            headers: $headers,
            serverParams: $swRequest->server ?? []
        );
    }
}