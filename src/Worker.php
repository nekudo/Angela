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
     * Input stream to receive control commands from server.
     *
     * @var Stream $readStream
     */
    protected $readStream;

    /**
     * Socket to received job requests.
     *
     * @var \React\ZMQ\SocketWrapper|\ZMQSocket $jobSocket
     */
    protected $jobSocket = null;

    /**
     * Socket to reply to server.
     *
     * @var \React\ZMQ\SocketWrapper|\ZMQSocket $replySocket
     */
    protected $replySocket = null;

    /**
     * Holds callbacks for each job a worker can do.
     *
     * @var array $callbacks
     */
    protected $callbacks = [];

    /**
     * Id of current job.
     *
     * @var int $jobId
     */
    protected $jobId = null;

    /**
     * @var string $workerId
     */
    protected $workerId = null;

    /**
     * Holds current worker-state.
     *
     * @var int $workerState
     */
    protected $workerState = 0;

    public function __construct()
    {
        // create workers main event loop:
        $this->loop = EventLoopFactory::create();

        $this->loadConfig();
        $this->setWorkerId();
        $this->createInputStream();
        $this->createJobSocket();
        $this->createReplySocket();
        $this->setState(Worker::WORKER_STATE_IDLE);
    }

    /**
     * Registers a type of job the worker is able to do.
     *
     * @param string $jobName
     * @param callable $callback
     * @return bool
     */
    public function registerJob(string $jobName, callable $callback) : bool
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
        return ($response === 'ok');
    }

    /**
     * Creates a stream to receive control commands from server and registers corresponding callbacks.
     */
    protected function createInputStream()
    {
        // creates input stream for worker:
        $this->readStream = new Stream(STDIN, $this->loop);

        // listen to commands on input stream:
        $this->readStream->on('data', function ($data) {
            $this->onCommand($data);
        });
    }

    /**
     * Creates socket to receive jobs from server and registers corresponding callbacks.
     */
    protected function createJobSocket()
    {
        /** @var Context|\ZMQContext $jobContext */
        $jobContext = new Context($this->loop);
        $this->jobSocket = $jobContext->getSocket(\ZMQ::SOCKET_SUB);
        $this->jobSocket->connect($this->config['sockets']['worker_job']);
        $this->jobSocket->on('messages', [$this, 'onJobMessage']);
    }

    /**
     * Creates socket used to reply to server.
     */
    protected function createReplySocket()
    {
        $replyContext = new \ZMQContext;
        $this->replySocket = $replyContext->getSocket(\ZMQ::SOCKET_REQ);
        $this->replySocket->connect($this->config['sockets']['worker_reply']);
    }

    /**
     * Handles commands the worker receives from server.
     *
     * @todo Implement command handling
     * @param string $input
     * @return bool
     */
    public function onCommand(string $input) : bool
    {
        return true;
    }

    /**
     * Handles job requests received from server.
     *
     * @param array $message
     * @return bool
     */
    public function onJobMessage(array $message) : bool
    {
        $jobName = $message[0];
        $jobId = $message[1];
        $workerId = $message[2];
        $payload = $message[3];

        // Skip if job is assigned to another worker:
        if ($workerId !== $this->workerId) {
            return false;
        }

        // Skip if worker can not handle the requested job
        if (!isset($this->callbacks[$jobName])) {
            throw new \RuntimeException('No callback found for requested job.');
        }

        // Switch to busy state handle job and switch back to idle state:
        $this->jobId = $jobId;
        $this->setState(Worker::WORKER_STATE_BUSY);
        call_user_func($this->callbacks[$jobName], $payload);
        $this->setState(Worker::WORKER_STATE_IDLE);
        $this->jobId = null;
        return true;
    }

    /**
     * Sets a new worker state and reports this state to server.
     *
     * @param int $state
     * @return bool
     */
    protected function setState(int $state) : bool
    {
        $this->workerState = $state;

        // report idle state to server:
        $this->replySocket->send(json_encode([
            'request' => 'change_state',
            'worker_id' => $this->workerId,
            'state' => $this->workerState
        ]));
        $response = $this->replySocket->recv();
        return ($response === 'ok');
    }

    /**
     * Loads configuration from config file passed in via argument.
     */
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

    /**
     * Sets ID of the worker using a server-id and the PID of the process.
     */
    protected function setWorkerId()
    {
        $pid = getmypid();
        $this->workerId = $this->config['server_id'] . '_' . $pid;
    }

    /**
     * Run main loop and wait for jobs.
     */
    public function run()
    {
        $this->loop->run();
    }
}
