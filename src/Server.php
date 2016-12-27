<?php
declare(strict_types=1);
namespace Nekudo\Angela;

use Nekudo\Angela\Logger\LoggerFactory;
use Overnil\EventLoop\Factory as EventLoopFactory;
use Psr\Log\LoggerInterface;
use React\ZMQ\Context;
use React\ChildProcess\Process;

class Server
{
    /**
     * @var array $config
     */
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
     * @var \ZMQContext $clientContext
     */
    protected $clientContext;

    /**
     * @var \React\ZMQ\SocketWrapper $ccSocket
     */
    protected $clientSocket;

    /**
     * @var \ZMQContext $workerContext
     */
    protected $workerContext;

    /**
     * @var \ZMQSocket $workerSocket
     */
    protected $workerSocket;

    protected $processes = [];

    public function __construct(array $config)
    {
        $this->config = $config;

        // create logger
        $loggerFactory = new LoggerFactory($config['logger']);
        $logger = $loggerFactory->create();
        $this->setLogger($logger);

        // Start servers event loop:
        $this->loop = EventLoopFactory::create();
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function start()
    {
        $this->startClientSocket();
        $this->startWorkerSocket();
        $this->startWorkerPools();
        $this->loop->run();
    }

    public function stop()
    {
        $this->loop->stop();
    }

    protected function startClientSocket()
    {
        $this->clientContext = new Context($this->loop);
        $this->clientSocket = $this->clientContext->getSocket(\ZMQ::SOCKET_REP);
        $this->clientSocket->bind($this->config['sockets']['client']);
        $this->clientSocket->on('message', [$this, 'onClientMessage']);
    }

    protected function startWorkerSocket()
    {
        $this->workerContext = new \ZMQContext;
        $this->workerSocket = $this->workerContext->getSocket(\ZMQ::SOCKET_PUB);
        $this->workerSocket->bind($this->config['sockets']['worker']);
    }

    public function onClientMessage(string $message)
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

        $this->clientSocket->send($response);
    }

    /**
     * Handles data received from child processes.
     *
     * @param string $output
     * @return bool
     */
    public function onChildProcessOut(string $output) : bool
    {
        var_dump($output);
        return true;
    }

    protected function startWorkerPools()
    {
        if (empty($this->config['pool'])) {
            throw new \RuntimeException('No worker pool defined. Check config file.');
        }
        foreach ($this->config['pool'] as $poolName => $poolConfig) {
            $this->startWorkerPool($poolName, $poolConfig);
        }
    }

    /**
     * Starts child processes as defined in pool configuration.
     *
     * @param string $poolName
     * @param array $poolConfig
     */
    protected function startWorkerPool(string $poolName, array $poolConfig)
    {
        if (!isset($poolConfig['worker_file'])) {
            throw new \RuntimeException('Path to worker file not set in pool config.');
        }

        $this->processes[$poolName] = [];
        $processesToStart = $poolConfig['cp_start'] ?? 5;
        for ($i = 0; $i < $processesToStart; $i++) {
            // start child process:
            try {
                $process = $this->startChildProcess($poolConfig['worker_file']);
                array_push($this->processes[$poolName], $process);
            } catch (\Exception $e) {
                var_dump($e->getMessage());
                $this->logger->warning(sprintf('Could not start child process. (Error: s)', $e->getMessage()));
            }
        }
    }

    /**
     * Starts a single child process/worker.
     *
     * HINT: We need to prepend php command with "exec" to avoid sh-wrapper.
     * @see https://github.com/symfony/symfony/issues/5759
     *
     * @param string $pathToFile
     * @return Process
     */
    protected function startChildProcess(string $pathToFile) : Process
    {
        $startupCommand = 'exec php ' . $pathToFile;
        $process = new Process($startupCommand);
        $process->start($this->loop);

        // listen to output from child process:
        $process->stdout->on('data', function ($output) {
            $this->onChildProcessOut($output);
        });

        return $process;
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
        $this->workerSocket->send($jobName, \ZMQ::MODE_SNDMORE);
        $this->workerSocket->send($payload);
        //$res = $this->workerSocket->recv();
        //return $res;
        return '';
    }
}
