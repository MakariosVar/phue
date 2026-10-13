<?php

declare(strict_types=1);
use function Phue\Core\env;

return [
    // The class that session logins resolve to, and the callable that resolves a
    // bearer token or JWT to the same class. Both are the application's model, so
    // both are named here rather than inside the module: a module that hardcoded
    // \App\Models\User could only ever be used by the application it was written
    // against, which is what made the Auth module un-installable anywhere else.
    //
    // Leaving them out falls back to BaseUser — an abstract model with no table —
    // which is enough for the framework's own tests and fails loudly and
    // meaningfully on first real login rather than at install time.
    'identityClass' => 'App\\Models\\User',
    'authResolver' => ['App\\Models\\User', 'findIdentityByAuthKey'],
    'jwt' => [
        'secret' => env('JWT_SECRET', 'test-secret-key-for-testing'),
        'ttl' => env('JWT_TTL', 3600),
        'algorithm' => 'HS256',
        'issuer' => 'app-name', // Optional
        'audience' => 'test-audience' // Optional
    ]
];