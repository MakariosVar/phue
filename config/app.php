<?php

use function Phue\Core\env;

return [
    'name'    => env('APP_NAME', 'Phue App'),
    'env'     => env('APP_ENV', 'development'),
    'debug'   => env('APP_DEBUG', true),
    'trustedProxies' => [],
    'modules' => [
        \Phue\Schii\SchiiModule::class, // Comment this on production
    ],
];