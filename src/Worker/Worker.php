<?php

namespace Nekudo\Angela\Worker;

use Nekudo\Angela\Broker\BrokerFactory;
use React\EventLoop\Factory;
use React\Stream\Stream;

abstract class Worker
{
    /**
     * @var \React\EventLoop\ExtEventLoop|\React\EventLoop\LibEventLoop
     * |\React\EventLoop\LibEvLoop|\React\EventLoop\StreamSelectLoop $loop
     */
    protected $loop;

    /**
     * @var Stream $readStream
     */
    protected $readStream;

    /**
     * @var \Nekudo\Angela\Broker\BrokerClient $broker
     */
    protected $broker;

    /**
     * Holds all tasks an corresponding callbacks a worker can handle.
     *
     * @var array $tasks
     */
    protected $tasks = [];

    public function __construct()
    {
        // create workers main event loop:
        $this->loop = Factory::create();

        // creates input stream for worker:
        $this->readStream = new Stream(STDIN, $this->loop);

        // listen to commands on input stream:
        $this->readStream->on('data', function ($data) {
            $this->onCommand($data);
        });

        // fetch messages from job queues:
        $this->loop->addPeriodicTimer(0.2, [$this, 'consume']);
    }

    /**
     * Registers a task. The worker will listen on a queue with corresponding name for new jobs.
     * If a job is received the callback will be executed.
     *
     * @param string $taskName
     * @param callable $callback
     * @return bool
     */
    public function registerTask(string $taskName, callable $callback) : bool
    {
        $this->tasks[$taskName] = $callback;
        return true;
    }

    /**
     * Handles commands the worker receives from the parent process.
     *
     * @param string $input
     */
    public function onCommand(string $input)
    {
        if (empty($input)) {
            // @todo throw exception invalid command
        }
        $message = json_decode($input, true);
        if (!isset($message['cmd'])) {
            // @todo Throw invalid command exection
        }
        switch ($message['cmd']) {
            case 'broker:connect':
                $this->connectToBroker($message['config']);
                break;
            default:
                // @todo Throw exception: invalid command
                break;
        }
    }

    /**
     * Checks all job queues for new jobs.
     */
    public function consume()
    {
        if (empty($this->tasks)) {
            // @todo throw exception "no tasks"...
        }
        foreach ($this->tasks as $queueName => $callback) {
            $msg = $this->broker->getLastMessageFromQueue($queueName);
            if (empty($msg)) {
                continue;
            }
            call_user_func($callback, $msg);
        }
    }

    /**
     * Connects to message broker.
     *
     * @param array $brokerConfig
     */
    protected function connectToBroker(array $brokerConfig)
    {
        $brokerFactory = new BrokerFactory($brokerConfig);
        $this->broker = $brokerFactory->create();
        foreach (array_keys($this->tasks) as $queueName) {
            $this->broker->initQueue($queueName);
        }
    }

    /**
     * Runs main loop waiting for new jobs or commands.
     */
    public function run()
    {
        $this->loop->run();
    }
}
