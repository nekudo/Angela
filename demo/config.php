<?php
return [
    'angela' => [

        // path to store log files:
        'logPath' => __DIR__ . '/logs/',

        'redis' => [
            'dsn' => 'tcp://127.0.0.1:6379',
        ],

        // Process pool configuration. Add as many pools as you like.
        'pool' => [

            // Unique name/identifier for each pool:
            'hello' => [

                // Path to the worker file:
                'worker_file' => __DIR__ . '/worker/hello.php',

                // Number of child processes created on startup:
                'cp_start' => 5,

                // Maximum number of child processes:
                'cp_max' => 10,
            ],
        ]
    ],
];