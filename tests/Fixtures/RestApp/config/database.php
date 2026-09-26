<?php

declare(strict_types=1);

// Use a file-based SQLite database so multiple Connection instances share the same DB
$dbPath = sys_get_temp_dir() . '/phue_rest_test_' . getmypid() . '.sqlite';

return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => $dbPath,
        ],
    ],
];