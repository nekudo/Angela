<?php

return [
    // Unique identifier for each server/instance:
    'server_id' => 's1',

    // Path to your server script:
    'server_path' => __DIR__ . '/server.php',

    // Path to configuration file (required to pass to worker processes)
    'config_path' => __FILE__,

    // Path to php-binary on your server:
    'php_path' => 'php',

    'logger' => [
        // possible loggers are: file, null
        'type' => 'file',

        'path' => __DIR__ . '/logs/',

        // possible levels are: emergency, alert, critical, error, warning, notice, info, debug
        'level' => 'debug',
    ],

    'sockets' => [
        // Command/Control socket (Use to send commands/jobs from client to server):
        'client' => 'tcp://127.0.0.1:5551',

        // Job socket (Used to push jobs from sever to workers);
        'worker_job' => 'ipc://worker_jobs.ipc',

        // Reply socket (Used to reply from worker to server):
        'worker_reply' => 'ipc://worker_reply.ipc',
    ],

    // Process pool configuration. Add as many pools as you like.
    'pool' => [

        // Unique name/identifier for each pool:
        'pool_a' => [

            // Path to the worker file:
            'worker_file' => __DIR__ . '/worker/worker_a.php',

            // Number of child processes created on startup:
            'cp_start' => 3,
        ],

        /* add as many pools as you like ...
        'pool_b' => [
            'worker_file' => __DIR__ . '/worker/worker_b.php',
            'cp_start' => 2,
        ],
        */
    ],
];
