<?php

namespace Nekudo\Angela\Demo;

//use PhpAmqpLib\Message\AMQPMessage;

require_once __DIR__ . '/../../vendor/autoload.php';

class Hello extends \Nekudo\Angela\Worker\Worker
{
    /**
     * @param AMQPMessage $message
     */
    public function fooTask($message)
    {

        $this->logger->debug('Executing task "fooTask" ...');

        $replyTo = $message->get('reply_to');
        $callbackId = $message->get('correlation_id');
        $type = $message->get('type');


        $this->broker->ack($message);


        $this->logger->debug(
            'ReplyTo: '. $replyTo .
            ' CallbackId: ' . $callbackId .
            ' Type: ' . $type
        );
        if ($type === 'normal') {
            $this->broker->respond($callbackId, 'bar response...');
        }

    }
}


$worker = new Hello;
$worker->registerTask('fooTask', [$worker, 'fooTask']);
$worker->run();

/*
$worker->onCommand((json_encode([
    'cmd' => 'init',
    'data' => [
        'logger' => [
            'path' => __DIR__ . '/../logs/',

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
                'worker_file' => __DIR__ . '/hello.php',

                // Number of child processes created on startup:
                'cp_start' => 5,
            ],
        ]
    ],
])));
*/