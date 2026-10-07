<?php

use function Phue\Core\env;

return [
    'name'    => env('APP_NAME', 'Phue App'),
    'env'     => env('APP_ENV', 'development'),
    'version' => env('APP_VERSION', '0.0.1'),
    'debug'   => env('APP_DEBUG', false),
    'devTool' => env('APP_DEV_TOOL', false),
    'trustedProxies' => [],
    'routeCache' => env('APP_ROUTE_CACHE', false),
    'themeToggle' => [
        'enabled'      => true,
        'storageKey'   => 'phue-theme',
        'defaultTheme' => 'system', // 'system' | 'light' | 'dark'
        'position'     => 'actions_prefix', // 'actions_prefix' | 'actions_suffix' | 'standalone'
        'ariaLabel'    => 'Toggle theme',
    ],
    'seo' => [
        'metaDescription' => env('META_DESCRIPTION', 'Almost a framework')
    ],
    'modules' => [
        \Phue\Modules\Auth\AuthModule::class,
        \Phue\Modules\Schii\SchiiModule::class, // Comment this on production
        // Real-time channels. Set APP_WEBSOCKET=true to switch it on; APP_DEBUG alone does not,
        // because config/websocket.php always supplies an explicit 'enabled'. Requires the
        // Swoole server, since a handshake only completes there: ./phue-docker start -co
        \Phue\Modules\Websocket\WebsocketModule::class,
        \Phue\Modules\Rest\RestModule::class,
        \Phue\Modules\Server\ServerModule::class,
        \Phue\Modules\Events\EventModule::class,
        \Phue\Modules\Compiler\CompilerModule::class,
        \Phue\Modules\DevTool\DevToolsModule::class,
        // Background jobs. No module is required, so mounting this cannot fail; with no driver
        // configured it runs jobs inline, which is what development wants and what makes a
        // dropped job impossible to mistake for a working one.
        \Phue\Modules\Queue\QueueModule::class,
    ],
];