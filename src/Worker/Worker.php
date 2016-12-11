<?php

namespace Nekudo\Angela\Worker;

use React\EventLoop\Factory;
use React\Stream\Stream;

abstract class Worker
{
    /**
     * @var array $config
     */
    protected $config = [];

    /**
     * @var \React\EventLoop\ExtEventLoop|\React\EventLoop\LibEventLoop
     * |\React\EventLoop\LibEvLoop|\React\EventLoop\StreamSelectLoop $loop
     */
    protected $loop;

    /**
     * @var Stream $readStream
     */
    protected $readStream;

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
    }

    /**
     * Initialize worker by setting config and connecting to message broker.
     *
     * @param array $config
     */
    public function init(array $config)
    {
        $this->setConfig($config);
        $this->connectToBroker();
        $this->closeInputStream();
        $this->wait();
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
            throw new \RuntimeException('Received invalid command frame.');
        }
        switch ($message['cmd']) {
            case 'init':
                $this->init($message['data']);
                break;
            default:
                return false;
        }
        return true;
    }

    /**
     * Closes the input/read stream.
     */
    protected function closeInputStream()
    {
        $this->readStream->close();
        unset($this->readStream);
        $this->loop->stop();
        unset($this->loop);
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
     * Runs main loop waiting for new jobs or commands.
     */
    public function run()
    {
        $this->loop->run();
    }

    /**
     * Connects to message broker.
     */
    abstract protected function connectToBroker();

    /**
     * Registers a callback/job the worker is capable to do.
     * If a job is received the callback will be executed.
     *
     * @param string $taskName
     * @param callable $callback
     * @return bool
     */
    abstract public function registerCallback(string $taskName, callable $callback) : bool;

    /**
     * Waits for new jobs.
     */
    abstract public function wait();
}
