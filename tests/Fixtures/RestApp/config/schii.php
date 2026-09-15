<?php

declare(strict_types=1);

return [
    'enabled' => true,
    'apiPrefix' => '/api/schii',
    'prefix' => '/schii',
    'entityNamespace' => 'Phue\\Tests\\Schii\\Stubs',
    'autoMigrate' => true,
    'cachePrefix' => 'schii_rest_schema_',
    'requireAuth' => true,
    'middleware' => [],
    'authResolver' => null,
    'exportDir' => 'storage/generated',
    'phiiNamespace' => 'Phue\\Tests\\Schii\\Stubs',
    'phiiPrefix' => '/phii',
];