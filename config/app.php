<?php

return [
    'name'  => 'Phue App',
    'env'   => 'development',
    'debug' => true,
    'modules' => [
        \Phue\Schii\SchiiModule::class,
    ],

    'schii' => [
        'apiPrefix' => '/api/schii',
        'entityNamespace' => 'App\\Models',
        'autoMigrate' => true,
        'cachePrefix' => 'schii_schema_',
    ],
];