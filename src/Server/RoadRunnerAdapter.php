<?php

declare(strict_types=1);

namespace Phue\Server;

use Phue\Core\Application;
use Phue\Core\Container;
use Phue\Core\Request;
use Phue\Core\Response;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Spiral\RoadRunner\WorkerInterface;

/**
 * Persistent RoadRunner Go application server adapter.
 *
 * @package Phue\Server
 */
final class RoadRunnerAdapter implements ServerAdapterInterface
{
    private ?Application $app = null;
    private ?Container $container = null;

    public function __construct(
        private readonly ?PSR7WorkerInterface $worker = null
    ) {}

    public function boot(Application $app): void
    {
        $this->app = $app;
        $this->container = $app->getContainer();
    }

    public function handleRequest(Request $request): Response
    {
        if ($this->app === null) {
            throw new \RuntimeException("RoadRunner adapter is not booted.");
        }

        return $this->app->handle($request);
    }

    public function run(): void
    {
        if ($this->app === null) {
            throw new \RuntimeException("RoadRunner adapter is not booted.");
        }

        // Fallback or injected RoadRunner Worker worker loop
        while ($psr7Request = $this->worker?->waitRequest()) {
            try {
                // Convert incoming PSR-7/RR Request to native Phue Request
                $request = Request::fromPsr7($psr7Request);

                $response = $this->handleRequest($request);

                // Send back native response
                $this->worker->respond($response->toPsr7());
            } catch (\Throwable $e) {
                $this->worker->respond(new \Nyholm\Psr7\Response(500, [], $e->getMessage()));
            } finally {
                // Crucial: Clear all request-scoped dependencies to prevent memory leaks
                $this->container?->flushScope();
            }
        }
    }

    public function shutdown(): void
    {
        // Signal RoadRunner worker termination
    }
}