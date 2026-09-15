<?php

declare(strict_types=1);

return [
    'name'    => 'Schii Test App',
    'env'     => 'testing',
    'debug'   => false,
    'devTool' => false,
    'trustedProxies' => [],
    'modules' => [
        \Phue\Schii\SchiiModule::class,
    ],
];