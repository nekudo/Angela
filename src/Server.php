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
     * @var \React\ZMQ\SocketWrapper $clientSocket
     */
    protected $clientSocket;

    /**
     * @var \ZMQContext $workerJobContext
     */
    protected $workerJobContext;

    /**
     * @var \ZMQSocket $workerJobSocket
     */
    protected $workerJobSocket;

    /**
     * @var \ZMQContext $workerReplyContext
     */
    protected $workerReplyContext;

    /**
     * @var \React\ZMQ\SocketWrapper $workerReplySocket
     */
    protected $workerReplySocket;

    /**
     * @var array $processes
     */
    protected $processes = [];

    /**
     * @var array $workerStates
     */
    protected $workerStates = [];

    protected $workerJobs = [];

    protected $workerStats = [];

    protected $jobQueues = [];

    protected $jobsInQueue = 0;

    /**
     * @var int $jobId
     */
    private $jobId = 0;

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
        $this->startWorkerJobSocket();
        $this->startWorkerReplySocket();
        $this->startWorkerPools();

        $this->loop->addPeriodicTimer(10, function () {
            echo 'Worker States:' . PHP_EOL;
            print_r($this->workerStates);
            echo 'Worker Stats:' . PHP_EOL;
            print_r($this->workerStats);
            echo 'Jobs Queue:' . $this->jobsInQueue . PHP_EOL;
        });

        $this->loop->run();
    }

    public function stop()
    {
        $this->stopWorkerPools();
        $this->loop->stop();
    }

    protected function startClientSocket()
    {
        $this->clientContext = new Context($this->loop);
        $this->clientSocket = $this->clientContext->getSocket(\ZMQ::SOCKET_REP);
        $this->clientSocket->bind($this->config['sockets']['client']);
        $this->clientSocket->on('message', [$this, 'onClientMessage']);
    }

    protected function startWorkerJobSocket()
    {
        $this->workerJobContext = new \ZMQContext;
        $this->workerJobSocket = $this->workerJobContext->getSocket(\ZMQ::SOCKET_PUB);
        $this->workerJobSocket->bind($this->config['sockets']['worker_job']);
    }

    protected function startWorkerReplySocket()
    {
        $this->workerReplyContext = new Context($this->loop);
        $this->workerReplySocket = $this->workerReplyContext->getSocket(\ZMQ::SOCKET_REP);
        $this->workerReplySocket->bind($this->config['sockets']['worker_reply']);
        $this->workerReplySocket->on('message', [$this, 'onWorkerReplyMessage']);
    }

    public function onClientMessage(string $message)
    {
        $data = json_decode($message, true);
        switch ($data['action']) {
            case 'command':
                $response = $this->handleCommand($data['command']['name']);
                break;
            case 'job':
                $response = $this->handleJobRequest($data['job']['name'], $data['job']['workload']);
                break;
            default:
                $response = 'Invalid action.';
                break;

        }

        $this->clientSocket->send($response);
    }

    public function onWorkerReplyMessage(string $message)
    {
        $data = json_decode($message, true);
        if (!isset($data['request'])) {
            throw new \RuntimeException('Invalid worker request received.');
        }
        switch ($data['request']) {
            case 'register_job':
                $response = $this->registerJob($data['job_name'], $data['worker_id']);
                break;
            case 'unregister_job':
                $response = $this->registerJob($data['job_name'], $data['worker_id']);
                break;
            case 'change_state':
                $response = $this->changeWorkerState($data['worker_id'], $data['state']);
                break;
            default:
                $response = 'Invalid request.';
                break;
        }
        $this->workerReplySocket->send($response);
    }

    protected function registerJob(string $jobName, string $workerId) : string
    {
        if (!isset($this->workerJobs[$jobName])) {
            $this->workerJobs[$jobName] = [];
        }
        if (!in_array($workerId, $this->workerJobs[$jobName])) {
            array_push($this->workerJobs[$jobName], $workerId);
        }
        return 'ok';
    }

    protected function unregisterJob(string $jobName, string $workerId) : string
    {
        if (!isset($this->workerJobs[$jobName])) {
            return 'ok';
        }
        if (($key = array_search($workerId, $this->workerJobs[$jobName])) !== false) {
            unset($this->workerJobs[$jobName][$key]);
        }
        if (empty($this->workerJobs[$jobName])) {
            unset($this->workerJobs[$jobName]);
        }
        return 'ok';
    }

    protected function changeWorkerState(string $workerId, int $workerState) : string
    {
        $this->workerStates[$workerId] = $workerState;
        if ($workerState === Worker::WORKER_STATE_IDLE) {
            $this->pushJobs();
        }
        return 'ok';
    }

    /**
     * Handles data received from child processes.
     *
     * @param string $output
     * @return bool
     */
    public function onChildProcessOut(string $output) : bool
    {
        echo "worker output: " . $output . PHP_EOL;
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
                $workerPid = $process->getPid();
                $workerId = $this->config['server_id'] . '_' . $workerPid;
                $this->workerStates[$workerId] = Worker::WORKER_STATE_IDLE;
            } catch (\Exception $e) {
                // @todo Add error handling
            }
        }
    }

    protected function stopWorkerPools()
    {
        if (empty($this->processes)) {
            return true;
        }
        foreach (array_keys($this->processes) as $poolName) {
            $this->stopWorkerPool($poolName);
        }
    }

    /**
     * Terminates all child processes of given pool.
     *
     * @param string $poolName
     * @return bool
     */
    protected function stopWorkerPool(string $poolName) : bool
    {
        if (empty($this->processes[$poolName])) {
            return true;
        }
        foreach ($this->processes[$poolName] as $process) {
            /** @var Process $process */
            $process->terminate();
        }
        return true;
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
        $pathToConfig = $this->config['config_path'];
        $startupCommand = 'exec php ' . $pathToFile . ' -c ' . $pathToConfig;
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

    protected function handleJobRequest(string $jobName, string $payload = '') : string
    {
        $jobId = $this->addJobToQueue($jobName, $payload);
        $this->pushJobs();
        return $jobId;
    }

    protected function addJobToQueue(string $jobName, string $payload = '') : string
    {
        if (!isset($this->jobQueues[$jobName])) {
            $this->jobQueues[$jobName] = [];
        }
        $jobId = $this->getJobId();
        array_push($this->jobQueues[$jobName], [
            'job_id' => $jobId,
            'payload' => $payload
        ]);
        $this->jobsInQueue++;
        return $jobId;
    }

    protected function pushJobs() : bool
    {
        if (empty($this->jobQueues)) {
            return true;
        }
        foreach (array_keys($this->jobQueues) as $jobName) {
            if (empty($this->jobQueues[$jobName])) {
                continue;
            }
            $workerId = $this->getOptimalWorkerId($jobName);
            if (empty($workerId)) {
                continue;
            }

            if (!isset($this->workerStats[$workerId])) {
                $this->workerStats[$workerId] = 0;
            }
            $this->workerStats[$workerId]++;

            // send job to worker:
            $this->jobsInQueue--;
            $jobData = array_shift($this->jobQueues[$jobName]);
            $this->workerJobSocket->send($jobName, \ZMQ::MODE_SNDMORE);
            $this->workerJobSocket->send($jobData['job_id'], \ZMQ::MODE_SNDMORE);
            $this->workerJobSocket->send($workerId, \ZMQ::MODE_SNDMORE);
            $this->workerJobSocket->send($jobData['payload']);
        }
        return true;
    }

    protected function getJobId() : string
    {
        $this->jobId++;
        return dechex($this->jobId);
    }

    protected function getOptimalWorkerId(string $jobName) : string
    {
        $workerIds = $this->workerJobs[$jobName];
        foreach ($workerIds as $workerId) {
            if ($this->workerStates[$workerId] === Worker::WORKER_STATE_IDLE) {
                return $workerId;
            }
        }
        return '';
    }
}
