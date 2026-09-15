<?php

declare(strict_types=1);
use function Phue\Core\env;

return [
    'enabled' => env('SCHII_ENABLED', true),
    'apiPrefix' => '/api/schii',
    'prefix' => '/schii',
    'entityNamespace' => 'App\\Models',
    'autoMigrate' => true,
    'cachePrefix' => 'schii_schema_',
    'requireAuth' => false,
    'middleware' => [],
    'authResolver' => ['App\\Models\\User', 'findIdentityByAuthKey'],
    'exportDir' => 'storage/generated',
    'phiiNamespace' => 'App\\Models',
    'phiiPrefix' => '/phii',
];