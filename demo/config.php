<?php
return [
    'angela' => [

        'logger' => [
            'path' => __DIR__ . '/logs/',

            // possible levels are: emergency, alert, critical, error, warning, notice, info, debug
            'level' => 'debug',
        ],

        'broker' => [
            'type' => 'rabbitmq',

            'queues' => [
                'cmd' => 'angela_cmd_1',
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
            'hello' => [

                // Path to the worker file:
                'worker_file' => __DIR__ . '/worker/hello.php',

                // Number of child processes created on startup:
                'cp_start' => 5,
            ],
        ]
    ],
];
