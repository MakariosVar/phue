<?php

declare(strict_types=1);

return [
    'name'    => 'Rest Test App',
    'env'     => 'testing',
    'debug'   => true,
    'devTool' => false,
    'trustedProxies' => [],
    'modules' => [
        \Phue\Schii\SchiiModule::class,
        \Phue\Rest\RestModule::class,
    ],
];