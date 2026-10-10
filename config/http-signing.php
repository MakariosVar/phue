<?php

declare(strict_types=1);

use function Phue\Core\env;

return [
    /**
     * Enable/disable response signing globally.
     */
    'enabled' => env('HTTP_SIGNING_ENABLED', true),

    /**
     * Signing algorithm: 'hmac-sha256' | 'rsa-pss-sha256'
     * - hmac-sha256: symmetric, fast, shared secret (default, no external deps)
     * - rsa-pss-sha256: asymmetric, requires OpenSSL extension
     */
    'algorithm' => env('HTTP_SIGNING_ALGO', 'hmac-sha256'),

    /**
     * Key configuration.
     * For HMAC: base64-encoded secret (32+ bytes recommended)
     * For RSA: array with 'private_key' (PEM) and optional 'public_key' (PEM)
     */
    'keys' => [
        'current' => [
            'id' => 'key-1',
            'secret' => env('HTTP_SIGNING_SECRET', 'base64:' . base64_encode(random_bytes(32))),
        ],
        // 'previous' => [
        //     'id' => 'key-0',
        //     'secret' => env('HTTP_SIGNING_SECRET_OLD', ''),
        // ],
    ],

    /**
     * Header names for the signature and metadata.
     */
    'headers' => [
        'signature' => 'X-Response-Signature',
        'algorithm' => 'X-Response-Signature-Algo',
        'key_id'    => 'X-Response-Signature-KeyId',
    ],

    /**
     * Paths to exclude from signing (exact match or prefix with *).
     * Health checks, static assets, etc.
     */
    'exclude_paths' => [
        '/health*',
        '/ping',
        '/metrics*',
        '/_debugbar*',
    ],

    /**
     * Which response headers to include in the signed payload.
     * Empty = sign only body. '*' = all headers.
     */
    'signed_headers' => [
        'Content-Type',
        'Content-Length',
        'ETag',
        'Last-Modified',
        'Cache-Control',
    ],

    /**
     * For asymmetric (RSA): whether to include public key in response for verification.
     * Not recommended for production; use JWKS endpoint instead.
     */
    'include_public_key' => false,
];