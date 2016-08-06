<?php

namespace Nekudo\Angela\Worker;

use React\EventLoop\Factory;
use React\Stream\Stream;
use Nekudo\Angela\Broker\RabbitmqClient;

abstract class Worker
{
    protected $loop;

    protected $readStream;

    protected $broker;

    public function __construct()
    {
        // create event loop for each worker:
        $this->loop = Factory::create();

        // creates input stream for each worker:
        $this->readStream = new Stream(STDIN, $this->loop);

        // listen to command on input stream:
        $this->readStream->on('data', function ($data) {
            $this->onCommand($data);
        });
    }

    public function onCommand(string $input)
    {
        $command = json_decode($input, true);
        switch ($command['cmd']) {
            case 'brokerConnect':
                $this->connectToBroker($command['config']);
                break;
            default:
                break;
        }
    }

    /**
     * Connects to message broker to be able to receive external commands.
     */
    protected function connectToBroker(array $brokerConfig)
    {
        switch ($brokerConfig['type']) {
            case 'rabbitmq':
                $this->broker = new RabbitmqClient;
                $this->broker->connect($brokerConfig['credentials']);
                break;
            default:
                // @todo throw unknown broker exception
                break;
        }
    }

    public function run()
    {
        $this->loop->run();
    }
}
