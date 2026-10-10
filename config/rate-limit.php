<?php

declare(strict_types=1);

use function Phue\Core\env;

return [
    /**
     * Master switch for the entire rate limiting system.
     */
    'enabled' => env('RATE_LIMIT_ENABLED', true),

    /**
     * Default policy applied when no attribute is present.
     * Set to null to disable global rate limiting.
     */
    'default' => null,
    // Or set global default rate limiting
    // 'default' => [
    //     'requests' => env('RATE_LIMIT_DEFAULT_REQUESTS', 100),
    //     'window'   => env('RATE_LIMIT_DEFAULT_WINDOW', 'minute'), // second, minute, hour, day
    //     'algorithm' => env('RATE_LIMIT_DEFAULT_ALGORITHM', 'sliding_window'), // sliding_window, token_bucket
    //     'key'      => 'ip', // ip, user, api_key, or callable
    // ],

    /**
     * Named policies that can be referenced by #[RateLimit(policy: 'name')].
     * These merge with the default policy (policy values override defaults).
     */
    'policies' => [
        'api' => [
            'requests' => 60,
            'window'   => 'minute',
            'algorithm' => 'token_bucket',
            'key'      => 'api_key',
        ],
        'strict' => [
            'requests' => 10,
            'window'   => 'minute',
            'algorithm' => 'sliding_window',
            'key'      => 'user',
        ],
        'login' => [
            'requests' => 5,
            'window'   => 'minute',
            'algorithm' => 'sliding_window',
            'key'      => 'ip',
        ],
        'register' => [
            'requests' => 3,
            'window'   => 'hour',
            'algorithm' => 'sliding_window',
            'key'      => 'ip',
        ],
    ],

    /**
     * Middleware group policies (applied to all routes in the group).
     * Key = middleware group name from config/app.php 'middlewareGroups'.
     */
    'groups' => [
        'api' => 'api',
        'web' => null, // no rate limit for web by default
    ],

    /**
     * Whitelist: IPs/CIDRs that bypass rate limiting entirely.
     * Supports CIDR notation (e.g., '10.0.0.0/8', '192.168.1.0/24').
     */
    'whitelist' => [
        '::1',
    ],

    /**
     * Redis connection configuration.
     * Uses the same connection as the websocket module if available.
     */
    'redis' => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'port'     => env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', null),
        'database' => env('REDIS_DATABASE', 0),
        'prefix'   => 'ratelimit:',
        'timeout'  => 2.0,
        'read_timeout' => 2.0,
    ],

    /**
     * Response customization.
     */
    'response' => [
        'status_code' => 429,
        'message'     => 'Too Many Requests',
        'include_retry_after_header' => true,
        'json_format' => true, // true = JSON response, false = plain text
    ],

    /**
     * Storage driver: 'redis' (default) or 'array' (for testing/single instance).
     */
    'driver' => env('RATE_LIMIT_DRIVER', 'redis'),
];