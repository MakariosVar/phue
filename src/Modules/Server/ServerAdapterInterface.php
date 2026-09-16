<?php

declare(strict_types=1);

namespace Phue\Modules\Server;

use Phue\Core\Application;
use Phue\Core\Request;
use Phue\Core\Response;

/**
 * Universal contract for web server runtimes (FPM, RoadRunner, Swoole, FrankenPHP).
 *
 * @package Phue\Modules\Server
 */
interface ServerAdapterInterface
{
    /**
     * Boots the application instance into the runtime environment.
     */
    public function boot(Application $app): void;

    /**
     * Converts native runtime input into Phue Request and generates Response.
     */
    public function handleRequest(Request $request): Response;

    /**
     * Starts the main server event loop or handles the single request lifecycle.
     */
    public function run(): void;

    /**
     * Gracefully shuts down the server runtime.
     */
    public function shutdown(): void;
}