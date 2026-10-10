<?php

declare(strict_types=1);

return [
    'authResolver' => ['App\\Models\\User', 'findIdentityByAuthKey'],
    'jwt' => [
        'secret' => 'test-secret-key-for-testing',
        'ttl' => 3600,
        'algorithm' => 'HS256',
        'audience' => 'test-audience'
    ]
];