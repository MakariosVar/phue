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
];