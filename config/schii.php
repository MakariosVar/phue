<?php

declare(strict_types=1);

return [
    'apiPrefix' => '/api/schii',
    'prefix' => '/schii',
    'entityNamespace' => 'App\\Models',
    'autoMigrate' => true,
    'cachePrefix' => 'schii_schema_',
    'requireAuth' => true,
    'middleware' => [],
    'authResolver' => ['App\\Models\\User', 'findIdentityByAuthKey'],
    'exportDir' => 'storage/generated',
    'phiiNamespace' => 'App\\Models',
    'phiiPrefix' => '/phii',
];