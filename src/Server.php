<?php
declare(strict_types=1);
namespace Nekudo\Angela;

use Nekudo\Angela\Logger\LoggerFactory;
use Overnil\EventLoop\Factory as EventLoopFactory;
use Psr\Log\LoggerInterface;
use React\ZMQ\Context;

class Server
{
    protected $config;

    /**
     * @var LoggerInterface $logger
     */
    protected $logger;

    /**
     * @var \React\EventLoop\LoopInterface $loop
     */
    protected $loop;

    /**
     * @var \ZMQContext $ccContext
     */
    protected $ccContext;

    /**
     * @var \React\ZMQ\SocketWrapper $ccSocket
     */
    protected $ccSocket;

    /**
     * @var \ZMQContext $workerContext
     */
    protected $workerContext;

    /**
     * @var \React\ZMQ\SocketWrapper $workerSocket
     */
    protected $workerSocket;

    public function __construct(array $config)
    {
        $this->config = $config;

        // create logger
        $loggerFactory = new LoggerFactory($config['logger']);
        $logger = $loggerFactory->create();
        $this->setLogger($logger);

        // Start servers event loop:
        $this->loop = EventLoopFactory::create();

        $this->startCommandSocket();
        $this->startWorkerSocket();
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function start()
    {
        $this->loop->run();
    }

    public function stop()
    {
        $this->loop->stop();
    }

    protected function startCommandSocket()
    {
        $this->ccContext = new Context($this->loop);
        $this->ccSocket = $this->ccContext->getSocket(\ZMQ::SOCKET_REP);
        $this->ccSocket->bind($this->config['sockets']['server_cc']);
        $this->ccSocket->on('message', [$this, 'onCCMessage']);
    }

    protected function startWorkerSocket()
    {
        $this->workerContext = new Context($this->loop);
        $this->workerSocket = $this->workerContext->getSocket(\ZMQ::SOCKET_PUSH);
        $this->workerSocket->bind($this->config['sockets']['worker']);
    }

    public function onCCMessage(string $message)
    {
        $data = json_decode($message, true);
        switch ($data['action']) {
            case 'command':
                $response = $this->handleCommand($data['command']['name']);
                break;
            case 'job':
                $response = $this->handleJob($data['job']['name'], $data['job']['workload']);
                break;
            default:
                $response = 'Invalid action.';
                break;

        }

        $this->ccSocket->send($response);
    }

    protected function handleCommand(string $command) : string
    {
        switch ($command) {
            case 'stop':
                $this->loop->stop();
                return 'ok';
        }
        return 'error';
    }

    protected function handleJob(string $jobName, string $payload = '') : string
    {
        return 'Server: Received job request. ' . $jobName;
    }
}
