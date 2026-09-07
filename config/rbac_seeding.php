<?php

declare(strict_types=1);

/**
 * RBAC Role and User Assignment Seed Configuration
 */
return [
    'roles' => [
        'admin' => [
            'admin.product.create',
            'admin.product.read',
            'admin.product.update',
            'admin.product.delete',
            'admin.user.create',
            'admin.user.read',
            'admin.user.update',
            'admin.user.delete',
        ],
        'editor' => [
            'admin.product.read',
            'admin.product.update',
        ],
    ],
    'assignments' => [
        1 => ['admin'],
        2 => ['editor'],
    ],
    'seed_users' => [
        [
            'id' => 1,
            'username' => 'admin',
            'auth_key' => 'token_admin_key',
            'password_hash' => password_hash('secret123', PASSWORD_BCRYPT),
            'roles' => ['admin'],
        ],
        [
            'id' => 2,
            'username' => 'editor',
            'auth_key' => 'token_editor_key',
            'password_hash' => password_hash('secret123', PASSWORD_BCRYPT),
            'roles' => ['editor'],
        ],
    ],
];