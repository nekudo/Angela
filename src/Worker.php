<?php
declare(strict_types=1);
namespace Nekudo\Angela;

use Overnil\EventLoop\Factory as EventLoopFactory;
use React\Stream\Stream;
use React\ZMQ\Context;

abstract class Worker
{
    const WORKER_STATE_UNINITIALIZED = 0;

    const WORKER_STATE_IDLE = 1;

    const WORKER_STATE_BUSY = 2;

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
     * @var Context $jobContext
     */
    protected $jobContext = null;

    /**
     * @var \React\ZMQ\SocketWrapper $jobSocket
     */
    protected $jobSocket = null;

    /**
     * @var Context $replyContext
     */
    protected $replyContext = null;

    /**
     * @var \React\ZMQ\SocketWrapper $replySocket
     */
    protected $replySocket = null;

    /**
     * @var array
     */
    protected $callbacks = [];

    /**
     * @var int $jobId
     */
    protected $jobId = 0;

    /**
     * @var string $workerId
     */
    protected $workerId = null;

    /**
     * @var int $workerState
     */
    protected $workerState = 0;

    public function __construct()
    {
        // create workers main event loop:
        $this->loop = EventLoopFactory::create();

        $this->loadConfig();
        $this->setWorkerId();
        $this->openInputStream();
        $this->startJobSocket();
        $this->startReplySocket();
        $this->setIdle();
    }

    public function registerJob(string $jobName, callable $callback)
    {
        $this->callbacks[$jobName] = $callback;
        $this->jobSocket->subscribe($jobName);

        // register job at server:
        $this->replySocket->send(json_encode([
            'request' => 'register_job',
            'worker_id' => $this->workerId,
            'job_name' => $jobName
        ]));
        $response = $this->replySocket->recv();
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

    protected function startJobSocket()
    {
        $this->jobContext = new Context($this->loop);
        $this->jobSocket = $this->jobContext->getSocket(\ZMQ::SOCKET_SUB);
        $this->jobSocket->connect($this->config['sockets']['worker_job']);
        $this->jobSocket->on('messages', [$this, 'onJobMessage']);
    }

    protected function startReplySocket()
    {
        $this->replyContext = new \ZMQContext;
        $this->replySocket = $this->replyContext->getSocket(\ZMQ::SOCKET_REQ);
        $this->replySocket->connect($this->config['sockets']['worker_reply']);
    }

    /**
     * Handles commands the worker receives from the parent process.
     *
     * @param string $input
     * @return bool
     */
    public function onCommand(string $input) : bool
    {
        return true;
    }

    public function onJobMessage(array $message)
    {
        $jobName = $message[0];
        $jobId = $message[1];
        $workerId = $message[2];
        $payload = $message[3];
        if ($workerId !== $this->workerId) {
            return true;
        }
        if (!isset($this->callbacks[$jobName])) {
            throw new \RuntimeException('No callback found for requested job.');
        }
        $this->setBusy();
        call_user_func($this->callbacks[$jobName], $payload);
        $this->setIdle();
    }

    protected function setBusy()
    {
        $this->workerState = self::WORKER_STATE_BUSY;

        // report idle state to server:
        $this->replySocket->send(json_encode([
            'request' => 'change_state',
            'worker_id' => $this->workerId,
            'state' => $this->workerState
        ]));
        $response = $this->replySocket->recv();
    }

    protected function setIdle()
    {
        $this->workerState = self::WORKER_STATE_IDLE;

        // report idle state to server:
        $this->replySocket->send(json_encode([
            'request' => 'change_state',
            'worker_id' => $this->workerId,
            'state' => $this->workerState
        ]));
        $response = $this->replySocket->recv();
    }

    protected function loadConfig()
    {
        $options = getopt('c:');
        if (!isset($options['c'])) {
            throw new \RuntimeException('No path to configuration provided.');
        }
        $pathToConfig = $options['c'];
        if (!file_exists($pathToConfig)) {
            throw new \RuntimeException('Config file not found.');
        }
        $this->config = require $pathToConfig;
    }

    protected function setWorkerId()
    {
        $pid = getmypid();
        $this->workerId = $this->config['server_id'] . '_' . $pid;
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
