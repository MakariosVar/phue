<?php

declare(strict_types=1);

namespace Phue\Core\Asset;

use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Routing\Attribute\Route;

/**
 * Serves static assets mapped under registered AssetRegistry source prefixes.
 *
 * @package Phue\Core\Asset
 */
class AssetsController
{
    public function __construct(
        private AssetServer $server
    ) {}

    /**
     * Match all multi-segment asset paths.
     */
    #[Route('/assets/{path}', methods: ['GET'], where: ['path' => '.*'])]
    public function serve(Request $request, array $params): Response
    {
        $path = $params['path'] ?? '';
        $uri = '/assets/' . ltrim($path, '/');

        $response = $this->server->serve($uri);

        return $response ?? new Response('Not Found', 404);
    }
}