<?php

namespace Nekudo\Angela\Demo;

require_once __DIR__ . '/../../vendor/autoload.php';

class Hello extends \Nekudo\Angela\Worker\Worker
{
    public function fooTask($message)
    {
        echo "executing task. received: " . $message . PHP_EOL;
    }
}


$worker = new Hello;
$worker->registerTask('fooTask', [$worker, 'fooTask']);

/*
$worker->onCommand((json_encode([
    'cmd' => 'init',
    'data' => [
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
])));
*/


$worker->run();
