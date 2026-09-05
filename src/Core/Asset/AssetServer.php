<?php

declare(strict_types=1);

namespace Phue\Core\Asset;

use Phue\Core\Response;

/**
 * Handles streaming static and dynamic assets with strict security hardening.
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

    /**
     * @param AssetRegistry $registry
     * @param bool $isDebug
     * @param int $maxSizeBytes Maximum allowed file size in bytes (Default: 5MB)
     */
    public function __construct(
        private AssetRegistry $registry,
        private bool $isDebug = false,
        private int $maxSizeBytes = 5_242_880
    ) {}

    /**
     * Attempt to serve static or dynamic asset based on requested URI.
     *
     * @param string $uri
     * @return Response|null Returns HTTP Response if route prefix matches, or null if unhandled
     */
    public function serve(string $uri): ?Response
    {
        $extension = strtolower(pathinfo(parse_url($uri, PHP_URL_PATH) ?? $uri, PATHINFO_EXTENSION));
        $expectedMime = $this->mimeTypes[$extension] ?? 'text/plain; charset=utf-8';

        // 1. Dynamic Resolvers Execution (e.g. /assets/phue/*.phue.js)
        $dynamicContent = $this->registry->resolveDynamic($uri);
        if ($dynamicContent !== null) {
            return $this->createResponse($dynamicContent, 'text/javascript; charset=utf-8');
        }

        // Check if URI matches any registered static source prefix
        if (!$this->registry->matchesSourcePrefix($uri)) {
            return null;
        }

        // 2. Physical File Path Resolution & Validation
        $filePath = $this->registry->resolvePath($uri);
        if ($filePath === null) {
            // A4: Non-HTML 404 for asset requests to prevent NS_ERROR_CORRUPTED_CONTENT
            return $this->createNotFoundResponse($expectedMime);
        }

        // 3. TOCTOU Protection & Atomic File Operations (A3)
        $fp = @fopen($filePath, 'rb');
        if ($fp === false) {
            return $this->createNotFoundResponse($expectedMime);
        }

        try {
            // Stat open file descriptor to prevent Time-of-Check to Time-of-Use race conditions
            $stat = fstat($fp);
            if ($stat === false) {
                return $this->createNotFoundResponse($expectedMime);
            }

            // A3 Refinement: Verify current canonical path EXACTLY matches pre-validated $filePath
            $currentRealPath = realpath($filePath);
            if ($currentRealPath === false || $currentRealPath !== $filePath) {
                return $this->createNotFoundResponse($expectedMime);
            }

            // A2: File Size Cap (Prevent Memory DoS Amplification)
            if ($stat['size'] > $this->maxSizeBytes) {
                return new Response('Asset exceeds maximum allowed file size', 413, [
                    'Content-Type'             => 'text/plain; charset=utf-8',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control'           => 'no-cache, no-store, must-revalidate',
                ]);
            }

            $content = stream_get_contents($fp);
            if ($content === false) {
                return $this->createNotFoundResponse($expectedMime);
            }
        } finally {
            fclose($fp);
        }

        return $this->createResponse($content, $expectedMime);
    }

    /**
     * Helper to assemble HTTP response with strict headers (A1: nosniff).
     */
    private function createResponse(string $content, string $contentType): Response
    {
        $headers = [
            'Content-Type'             => $contentType,
            'Content-Length'           => (string) strlen($content),
            'X-Content-Type-Options' => 'nosniff', // A1
        ];

        if ($this->isDebug) {
            $headers['Cache-Control'] = 'no-cache, no-store, must-revalidate';
        } else {
            $headers['Cache-Control'] = 'public, max-age=31536000, immutable';
        }

        return new Response($content, 200, $headers);
    }

     /**
     * Helper to create 404 responses matching requested MIME type with zero caching (A4).
     */
    private function createNotFoundResponse(string $contentType): Response
    {
        return new Response('Asset Not Found', 404, [
            'Content-Type'             => $contentType,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'           => 'no-cache, no-store, must-revalidate',
        ]);
    }
}