<?php

declare(strict_types=1);

namespace Phue\Modules\Server;

use Phue\Core\Application;
use Phue\Core\Container;
use Phue\Core\Request;
use Phue\Core\Response;
use RuntimeException;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Throwable;

/**
 * Persistent RoadRunner Go application server adapter.
 *
 * Coordinates request handling between the RoadRunner worker loop
 * and the Phue application kernel lifecycle.
 *
 * @package Phue\Modules\Server
 */
final class RoadRunnerAdapter implements ServerAdapterInterface
{
    use AdapterErrorBoundaryTrait;

    /**
     * The application instance.
     */
    private ?Application $app = null;

    /**
     * Initializes the RoadRunner adapter instance.
     *
     * @param PSR7WorkerInterface|null $worker Optional PSR-7 RoadRunner worker instance.
     */
    public function __construct(
        private readonly ?PSR7WorkerInterface $worker = null
    ) {}

    /**
     * Boots the adapter with the application kernel.
     *
     * @param Application $app The application kernel instance.
     * @return void
     */
    public function boot(Application $app): void
    {
        $this->app = $app;
    }

    /**
     * Handles an incoming Phue request through the application kernel.
     *
     * @param Request $request The incoming Phue HTTP request.
     * @return Response The generated HTTP response.
     * @throws RuntimeException If the adapter has not been booted.
     */
    public function handleRequest(Request $request): Response
    {
        if ($this->app === null) {
            throw new RuntimeException("RoadRunner adapter is not booted.");
        }

        return $this->app->handle($request);
    }

    /**
     * Executes the main RoadRunner worker loop.
     *
     * Continuously listens for incoming PSR-7 HTTP requests from RoadRunner,
     * processes them via the kernel, and sends back the PSR-7 response.
     *
     * @return void
     * @throws RuntimeException If the adapter has not been booted.
     */
    public function run(): void
    {
        if ($this->app === null) {
            throw new RuntimeException("RoadRunner adapter is not booted.");
        }

        while ($psr7Request = $this->worker?->waitRequest()) {
            // Stays null until the PSR-7 request is converted, because a conversion
            // failure still has to produce a response.
            $request = null;

            try {
                // Convert incoming PSR-7/RR Request to native Phue Request
                $request = Request::fromPsr7($psr7Request);

                $psr7Response = $this->handleRequest($request)->toPsr7();
            } catch (Throwable $e) {
                $psr7Response = $this->renderFailure($e, $request)->toPsr7();
            }

            // Outside the try: exactly one response per request. A worker that fails while
            // serialising must not be answered twice, and must keep serving the next one.
            $this->worker->respond($psr7Response);
        }
    }

    /**
     * Resolves the container the error boundary renders through.
     *
     * Resolved lazily and only from the failure path, so a request that is handled
     * normally never pays for a container lookup it does not need.
     *
     * @return Container|null Null when the adapter has not been booted.
     */
    protected function errorBoundaryContainer(): ?Container
    {
        return $this->app?->getContainer();
    }

    /**
     * Gracefully shuts down the adapter and signals worker termination.
     *
     * @return void
     */
    public function shutdown(): void
    {
        // Signal RoadRunner worker termination
    }
}