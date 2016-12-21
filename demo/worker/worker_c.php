<?php

namespace Nekudo\Angela\Demo;

use Overnil\EventLoop\Factory as EventLoopFactory;
use React\ZMQ\Context;

require_once __DIR__ . '/../../vendor/autoload.php';

class WorkerC
{
    protected $loop = null;

    /** @var Context $context  */
    protected $context = null;

    protected $socket = null;

    public function __construct()
    {
        $this->loop = EventLoopFactory::create();
        $this->context = new Context($this->loop);
        $this->socket = $this->context->getSocket(\ZMQ::SOCKET_PULL);
        $this->socket->bind("tcp://127.0.0.1:5555");
        $this->socket->on('message', [$this, 'onMessage']);
    }

    public function run()
    {
        $this->loop->run();
    }

    public function onMessage($message)
    {
        var_dump($message);
    }
}

$worker = new WorkerC;
$worker->run();
