<?php

use function Phue\Core\env;

return [
    'name'    => env('APP_NAME', 'Phue App'),
    'env'     => env('APP_ENV', 'development'),
    'debug'   => env('APP_DEBUG', true),
    'devTool' => env('APP_DEV_TOOL', true),
    'trustedProxies' => [],
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