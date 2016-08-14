<?php

namespace Nekudo\Angela\Worker;

use Katzgrau\KLogger\Logger;
use Nekudo\Angela\Broker\BrokerFactory;
use Nekudo\Angela\Logger\LoggerFactory;
use React\EventLoop\Factory;
use React\Stream\Stream;

abstract class Worker
{
    /**
     * Will be "true" once init method was called.
     *
     * @var bool $isInitialized
     */
    public $isInitialized = false;

    /**
     * @var array $config
     */
    protected $config = [];

    /**
     * @var $logger Logger
     */
    protected $logger;

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
     * @return bool
     */
    public function onCommand(string $input) : bool
    {
        if (empty($input)) {
            return false;
        }
        $message = json_decode($input, true);
        if (!isset($message['cmd'])) {
            if ($this->isInitialized === true) {
                $this->logger->warning('Invalid command message received.');
            }
            return false;
        }
        if ($this->isInitialized === true) {
            $this->logger->debug('Received command: ' . $message['cmd']);
        }
        switch ($message['cmd']) {
            case 'init':
                $this->init($message['data']);
                break;
            default:
                if ($this->isInitialized === true) {
                    $this->logger->warning('Received unknown command.');
                }
                return false;
                break;
        }
        return true;
    }

    /**
     * Initialize worker by setting config and connecting to message broker.
     *
     * @param array $config
     */
    public function init(array $config)
    {
        $this->setConfig($config);
        $this->createLogger();
        $this->connectToBroker();
        $this->isInitialized = true;
    }

    /**
     * Checks all job queues for new jobs.
     *
     * @return bool
     */
    public function consume() : bool
    {
        if (empty($this->tasks)) {
            if ($this->isInitialized === true) {
                $this->logger->notice('Consume method called but no tasks registed.');
            }
            return false;
        }
        foreach ($this->tasks as $queueName => $callback) {
            $msg = $this->broker->getLastMessageFromQueue($queueName);
            if (empty($msg)) {
                continue;
            }
            call_user_func($callback, $msg);
        }
        return true;
    }

    /**
     * Sets project configuration.
     *
     * @param array $config
     */
    protected function setConfig(array $config)
    {
        $this->config = $config;
    }

    /**
     * Creates new instance of logger object.
     */
    protected function createLogger()
    {
        $loggerFactory = new LoggerFactory($this->config['logger']);
        $this->logger = $loggerFactory->create();
        unset($loggerFactory);
    }

    /**
     * Connects to message broker.
     */
    protected function connectToBroker()
    {
        $brokerFactory = new BrokerFactory($this->config['broker']);
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
