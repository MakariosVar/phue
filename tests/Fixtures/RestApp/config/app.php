<?php

declare(strict_types=1);

return [
    'name'    => 'Rest Test App',
    'env'     => 'testing',
    'debug'   => true,
    'devTool' => false,
    'trustedProxies' => [],
    'modules' => [
        \Phue\Modules\Schii\SchiiModule::class,
        \Phue\Modules\Rest\RestModule::class,
    ],
];