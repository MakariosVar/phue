<?php

use function Phue\Core\env;

return [
    'name'    => env('APP_NAME', 'Phue App'),
    'env'     => env('APP_ENV', 'development'),
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
        \Phue\Modules\Rest\RestModule::class,
        \Phue\Modules\Server\ServerModule::class,
        \Phue\Modules\Events\EventModule::class,
        \Phue\Modules\Compiler\CompilerModule::class,
        \Phue\Modules\DevTool\DevToolsModule::class,
    ],
];