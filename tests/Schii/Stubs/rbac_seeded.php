<?php

declare(strict_types=1);

return [
    'roles' => [
        'admin' => [
            'admin.product.create',
            'admin.product.read',
            'admin.product.update',
            'admin.product.delete',
        ],
        'editor' => [
            'admin.product.read',
            'admin.product.update',
        ],
        'wildcard' => [
            '*',
        ],
    ],
    'assignments' => [
        1 => ['admin'],
        2 => ['editor'],
        3 => ['wildcard'],
    ],
];