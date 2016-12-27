<?php
declare(strict_types=1);
namespace Nekudo\Angela;

use Overnil\EventLoop\Factory as EventLoopFactory;
use React\Stream\Stream;
use React\ZMQ\Context;

abstract class Worker
{
    /**
     * @var array $config
     */
    protected $config = [];

    /**
     * @var \React\EventLoop\LoopInterface $loop
     */
    protected $loop;

    /**
     * @var Stream $readStream
     */
    protected $readStream;

    /**
     * @var Context $context
     */
    protected $context = null;

    /**
     * @var \React\ZMQ\SocketWrapper $socket
     */
    protected $socket = null;

    /**
     * @var array
     */
    protected $callbacks = [];

    public function __construct()
    {
        // create workers main event loop:
        $this->loop = EventLoopFactory::create();

        $this->openInputStream();
        $this->startWorkerSocket();
    }

    public function registerJob(string $jobName, callable $callback)
    {
        $this->callbacks[$jobName] = $callback;
        $this->socket->subscribe($jobName);
    }

    protected function openInputStream()
    {
        // creates input stream for worker:
        $this->readStream = new Stream(STDIN, $this->loop);

        // listen to commands on input stream:
        $this->readStream->on('data', function ($data) {
            $this->onCommand($data);
        });
    }

    protected function startWorkerSocket()
    {
        $this->context = new Context($this->loop);
        $this->socket = $this->context->getSocket(\ZMQ::SOCKET_SUB);
        $this->socket->connect("tcp://127.0.0.1:5552");
        $this->socket->on('messages', [$this, 'onJobMessage']);
    }

    /**
     * Handles commands the worker receives from the parent process.
     *
     * @param string $input
     * @return bool
     */
    public function onCommand(string $input) : bool
    {
        var_dump($input);
        return true;
    }

    public function onJobMessage(array $message)
    {
        $jobName = $message[0];
        $payload = $message[1];
        if (!isset($this->callbacks[$jobName])) {
            throw new \RuntimeException('No callback found for requested job.');
        }
        call_user_func($this->callbacks[$jobName], $payload);
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

    public function run()
    {
        $this->loop->run();
    }

}
