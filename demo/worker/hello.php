<?php

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
    'cmd' => 'broker:connect',
    'config' => [
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
])));
*/


$worker->run();
