<?php
declare(strict_types=1);
namespace Nekudo\Angela;

// @todo Log possible errors

class Client
{
    /**
     * @var string $dsn
     */
    protected $dsn;

    /**
     * @var \ZMQSocket $socket
     */
    protected $socket = null;

    /**
     * Connects to a server socket.
     *
     * @param string $dsn
     * @return bool
     */
    public function addServer(string $dsn) : bool
    {
        try {
            $context = new \ZMQContext;
            $this->socket = $context->getSocket(\ZMQ::SOCKET_REQ);
            $this->socket->connect($dsn);
            $this->dsn = $dsn;
            return true;
        } catch (\ZMQException $e) {
            var_dump($e->getMessage());
            return false;
        }
    }

    /**
     * Executes a job in "normal" or blocking mode. Waits until result is received from server.
     *
     * @param string $jobName
     * @param string $workload
     * @return string The job result.
     */
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
        return '';
    }

    /**
     * Executes a job in background or non-blocking mode. Returns the job handle received from server but not a
     * job result.
     *
     * @param string $jobName
     * @param string $workload
     * @return string Job handle
     */
    public function doBackground(string $jobName, string $workload) : string
    {
        try {
            $this->socket->send(json_encode([
                'action' => 'background_job',
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
        return '';
    }

    /**
     * Sends a controll command to server and returns servers response.
     *
     * @param string $command
     * @return string
     */
    public function sendCommand(string $command) : string
    {
        try {
            $this->socket->send(json_encode([
                'action' => 'command',
                'command' => [
                    'name' => $command,
                ]
            ]));
            $result = $this->socket->recv();
            return $result;
        } catch (\ZMQException $e) {
            var_dump($e->getMessage());
        }
        return '';
    }

    /**
     * Closes socket connection to server.
     */
    public function close()
    {
        $this->socket->disconnect($this->dsn);
    }
}
