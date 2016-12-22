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
        $this->loop = EventLoopFactory::create();
        $this->ccContext = new Context($this->loop);
        $this->ccSocket = $this->ccContext->getSocket(\ZMQ::SOCKET_REP);
        $this->ccSocket->bind($this->config['sockets']['server_cc']);
        $this->ccSocket->on('message', [$this, 'onCommand']);
    }

    public function onCommand(string $message)
    {
        switch ($message) {
            case 'stop':
                $response = 'ok';
                $this->stop();
                break;
            default:
                $response = 'error';
                break;
        }
        $this->ccSocket->send($response);
    }
}
