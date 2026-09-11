<?php

declare(strict_types=1);

use function Phue\Core\env;

/**
 * Server-Side Rendering (SSR) Configuration
 *
 * Configures the connection parameters, limits, and state for the SSR engine execution.
 */
return [
    /**
     * Master toggle for enabling or disabling Server-Side Rendering.
     */
    'enabled' => (bool) env('SSR_ENABLED', true),

    /**
     * Unix domain socket path for communicating with the SSR sidecar service.
     * Note: Abstract sockets starting with "\0" must remain hardcoded in config files.
     */
    'socket' => "\0phue-ssr",

    /**
     * Maximum time in milliseconds to wait when opening a socket connection.
     */
    'connect_timeout_ms' => (int) env('SSR_CONNECT_TIMEOUT_MS', 200),

    /**
     * Maximum time in milliseconds to wait when reading data from the connected socket stream.
     */
    'timeout_ms' => (int) env('SSR_TIMEOUT_MS', 500),

    /**
     * HTTP Host header value sent in sidecar request headers.
     */
    'host_header' => 'phue-ssr',

    /**
     * Maximum payload threshold size allowed in Kilobytes before aborting rendering.
     */
    'max_payload_kb' => (int) env('SSR_MAX_PAYLOAD_KB', 512),
];