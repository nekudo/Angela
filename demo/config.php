<?php
return [
    'angela' => [

        // Path to your angela script:
        'script_path' => __DIR__ . '/angela.php',

        // Path to php-binary on your server:
        'php_path' => 'php',

        'logger' => [
            'path' => __DIR__ . '/logs/',

            // possible levels are: emergency, alert, critical, error, warning, notice, info, debug
            'level' => 'debug',
        ],

        'broker' => [
            'type' => 'rabbitmq',

            'queues' => [
                'cmd' => 'angela_cmd_1',
                'callback' => 'angela_cb_1',
            ],

            'credentials' => [
                'host' => 'localhost',
                'port' => 5672,
                'username' => 'guest',
                'password' => 'guest',
            ],
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
        ]
    ],
];
