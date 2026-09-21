<?php

declare(strict_types=1);

/**
 * RBAC Role and User Assignment Seed Configuration
 */
return [
    'roles' => [
        'admin' => ['*'],
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