<?php
declare(strict_types=1);
namespace Nekudo\Angela;

class Client
{
    protected $dsn;

    /**
     * @var \ZMQContext $context
     */
    protected $context = null;

    /**
     * @var \ZMQSocket $socket
     */
    protected $socket = null;

    public function addServer(string $dsn) : bool
    {
        try {
            $this->context = new \ZMQContext;
            $this->socket = $this->context->getSocket(\ZMQ::SOCKET_REQ);
            $this->socket->connect($dsn);
            $this->dsn = $dsn;
            return true;
        } catch (\ZMQException $e) {
            var_dump($e->getMessage());
            return false;
        }
    }

    public function doNormal(string $jobName, string $workload) : string
    {
        try {
            $this->socket->send(json_encode([
                'action' => 'job',
                'job' => [
                    'name' => $jobName,
                    'workload' => $workload
                ]
            ]));
            $result = $this->socket->recv();
            return $result;
        } catch (\ZMQException $e) {
            var_dump($e->getMessage());
        }
    }

    public function doBackground()
    {

    }

    public function close()
    {
        $this->socket->disconnect($this->dsn);
    }
}
