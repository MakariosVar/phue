<?php

declare(strict_types=1);

namespace Phue\Modules\Server;

use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Core\Response;

/**
 * Standard PHP-FPM / CGI adapter for stateless single-request execution.
 *
 * @package Phue\Modules\Server
 */
final class FpmAdapter implements ServerAdapterInterface
{
    private ?Application $app = null;

    public function boot(Application $app): void
    {
        $this->app = $app;
    }

    public function handleRequest(Request $request): Response
    {
        if ($this->app === null) {
            throw new \RuntimeException("Server adapter must be booted with an Application instance before handling requests.");
        }

        return $this->app->handle($request);
    }

    public function run(): void
    {
        // Capture global HTTP request context
        $request = Request::capture();
        
        $response = $this->handleRequest($request);
        
        // Emit response headers and body to Output Buffer
        $response->send();
    }

    public function shutdown(): void
    {
        // FPM handles process cleanup automatically per request end
    }
}