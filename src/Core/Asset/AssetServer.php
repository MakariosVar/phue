<?php

declare(strict_types=1);

namespace Phue\Core\Asset;

use Phue\Core\Response;

/**
 * Handles streaming static assets with appropriate Content-Type and caching guards.
 *
 * @package Phue\Core\Asset
 */
class AssetServer
{
    /** @var array<string, string> Extension to MIME Type map */
    private array $mimeTypes = [
        'js'    => 'text/javascript; charset=utf-8',
        'mjs'   => 'text/javascript; charset=utf-8',
        'css'   => 'text/css; charset=utf-8',
        'json'  => 'application/json',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'ico'   => 'image/x-icon',
        'woff2' => 'font/woff2',
    ];

    public function __construct(
        private AssetRegistry $registry,
        private bool $isDebug = false
    ) {}

    /**
     * Attempt to serve static asset based on requested URI.
     *
     * @param string $uri
     * @return Response|null Returns HTTP Response if resolved, or null if no matching source exists
     */
    public function serve(string $uri): ?Response
    {
        $filePath = $this->registry->resolvePath($uri);

        if ($filePath === null) {
            return null;
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $contentType = $this->mimeTypes[$extension] ?? 'text/plain; charset=utf-8';

        $content = file_get_contents($filePath);
        if ($content === false) {
            return new Response('Unable to read asset file', 500);
        }

        $response = new Response($content, 200, [
            'Content-Type' => $contentType,
            'Content-Length' => (string) strlen($content),
        ]);

        if ($this->isDebug) {
            $response->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        } else {
            $response->setHeader('Cache-Control', 'public, max-age=31536000, immutable');
        }

        return $response;
    }
}