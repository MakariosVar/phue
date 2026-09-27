<?php

declare(strict_types=1);

use function Phue\Core\env;

/**
 * Server-Side Rendering (SSR) Configuration
 *
 * Configures connection parameters, compilation caching, protocol switches, and limits
 * for the Bun SSR sidecar execution engine.
 */
return [
    /**
     * Master toggle for enabling or disabling Server-Side Rendering.
     */
    'enabled' => (bool) env('SSR_ENABLED', true),

    /**
     * Unix domain socket filesystem path for communicating with the SSR sidecar service.
     */
    'socket' => (string) env('SSR_SOCKET', '/tmp/phue-ssr.sock'),

    /**
     * Maximum time in milliseconds to wait when opening a socket connection.
     */
    'connect_timeout_ms' => (int) env('SSR_CONNECT_TIMEOUT_MS', 200),

    /**
     * Maximum time in milliseconds to wait when reading data from the connected socket stream.
     */
    'timeout_ms' => (int) env('SSR_TIMEOUT_MS', 2000),

    /**
     * HTTP Host header value sent in sidecar request headers.
     */
    'host_header' => (string) env('SSR_HOST_HEADER', 'phue-ssr'),

    /**
     * Maximum payload threshold size allowed in Kilobytes before aborting rendering.
     */
    'max_payload_kb' => (int) env('SSR_MAX_PAYLOAD_KB', 512),
];