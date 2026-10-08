<?php

declare(strict_types=1);

return [
    'name'    => 'Rest Test App',
    'env'     => 'testing',
    'debug'   => true,
    'devTool' => false,
    'trustedProxies' => [],
    // CSRF signs with this. A fixed value on purpose: a fixture that generated a random
    // key per test would make assertions depend on secret material.
    'appKey' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'modules' => [
        \Phue\Modules\Auth\AuthModule::class,
        \Phue\Modules\Schii\SchiiModule::class,
        \Phue\Modules\Rest\RestModule::class,
    ],
];