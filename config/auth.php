<?php

declare(strict_types=1);
use function Phue\Core\env;

return [
    'authResolver' => ['App\\Models\\User', 'findIdentityByAuthKey'],
    'jwt' => [
        'secret' => env('JWT_SECRET', 'test-secret-key-for-testing'),
        'ttl' => env('JWT_TTL', 3600),
        'algorithm' => 'HS256',
        'audience' => 'test-audience'
    ]
];