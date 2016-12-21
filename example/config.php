<?php

return [
    // Path to your angela script:
    'script_path' => __DIR__ . '/angela.php',

    // Path to php-binary on your server:
    'php_path' => 'php',

    'logger' => [
        // possible loggers are: file, null
        'type' => 'file',

        'path' => __DIR__ . '/logs/',

        // possible levels are: emergency, alert, critical, error, warning, notice, info, debug
        'level' => 'debug',
    ],

    'broker' => [
        'type' => 'gearman',

        'credentials' => [
            'host' => 'localhost',
            'port' => 4739,
            'username' => '',
            'password' => '',
        ],
    ],

    'socket' => [
        'host' => '127.0.0.1',
        'port' => '1338',
    ],

    // Process pool configuration. Add as many pools as you like.
    'pool' => [

        // Unique name/identifier for each pool:
        'pool_a' => [

            // Path to the worker file:
            'worker_file' => __DIR__ . '/worker/worker_a.php',

            // Number of child processes created on startup:
            'cp_start' => 2,
        ],

        'pool_b' => [
            'worker_file' => __DIR__ . '/worker/worker_b.php',
            'cp_start' => 2,
        ],
    ],
];
