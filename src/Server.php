<?php
declare(strict_types=1);
namespace Nekudo\Angela;

use Nekudo\Angela\Logger\LoggerFactory;
use Overnil\EventLoop\Factory as EventLoopFactory;
use Psr\Log\LoggerInterface;
use React\ZMQ\Context;
use React\ChildProcess\Process;

/**
 * @todo Add periodic timer to check for "lost jobs" in queue.
 * @todo Add periodic timer to check worker process "health".
 * @todo Use logger
 * @todo Use exceptions
 */

class Server
{
    /**
     * Holds the server configuration.
     *
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
     * A reply-socket used to receive data (commands, job-requests, e.g.) from clients.
     *
     * @var \React\ZMQ\SocketWrapper $clientSocket
     */
    protected $clientSocket;

    /**
     * A publish-socket used to distribute jobs to worker processes.
     *
     * @var \ZMQSocket $workerJobSocket
     */
    protected $workerJobSocket;

    /**
     * A reply-socket used to receive data from worker-processes.
     *
     * @var \React\ZMQ\SocketWrapper $workerReplySocket
     */
    protected $workerReplySocket;

    /**
     * Holds all child processes.
     *
     * @var array $processes
     */
    protected $processes = [];

    /**
     * Holds states (busy, idle, ...) of all known worker-processes.
     *
     * @var array $workerStates
     */
    protected $workerStates = [];

    /**
     * Holds information on which worker can do which kind of jobs.
     *
     * @var array $workerJobs
     */
    protected $workerJobs = [];

    /**
     * Holds worker statistics.
     *
     * @var array $workerStats
     */
    protected $workerStats = [];

    /**
     * Holds job-requests separated by job-type.
     *
     * @var array $jobQueues
     */
    protected $jobQueues = [];

    /**
     * Stores how many job-requests are currently in queue.
     *
     * @var int $jobsInQueue
     */
    protected $jobsInQueue = 0;

    /**
     * Holds type (background or normal) for each job.
     *
     * @var array $jobTypes
     */
    protected $jobTypes = [];

    /**
     * Stores client addresses for jobs to be able to reply asynchronously.
     *
     * @var array $jobAddresses
     */
    protected $jobAddresses = [];

    /**
     * Simple job counter used to generate job ids.
     *
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

    /**
     * Injects logger
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Creates sockets and fires up worker processes.
     */
    public function start()
    {
        $this->createClientSocket();
        $this->createWorkerJobSocket();
        $this->createWorkerReplySocket();
        $this->startWorkerPools();
        $this->loop->run();
    }

    /**
     * Close sockets, stop worker processes and stop main event loop.
     */
    public function stop()
    {
        $this->stopWorkerPools();
        $this->workerJobSocket->disconnect($this->config['sockets']['worker_job']);
        $this->workerReplySocket->close();
        $this->clientSocket->close();
        $this->loop->stop();
    }

    /**
     * Creates client-socket and assigns listener.
     */
    protected function createClientSocket()
    {
        /** @var Context|\ZMQContext $clientContext */
        $clientContext = new Context($this->loop);
        $this->clientSocket = $clientContext->getSocket(\ZMQ::SOCKET_ROUTER);
        $this->clientSocket->bind($this->config['sockets']['client']);
        $this->clientSocket->on('messages', [$this, 'onClientMessage']);
    }

    /**
     * Creates worker-job-socket.
     */
    protected function createWorkerJobSocket()
    {
        $workerJobContext = new \ZMQContext;
        $this->workerJobSocket = $workerJobContext->getSocket(\ZMQ::SOCKET_PUB);
        $this->workerJobSocket->bind($this->config['sockets']['worker_job']);
    }

    /**
     * Creates worker-reply-socket and assigns listener.
     */
    protected function createWorkerReplySocket()
    {
        /** @var Context|\ZMQContext $workerReplyContext */
        $workerReplyContext = new Context($this->loop);
        $this->workerReplySocket = $workerReplyContext->getSocket(\ZMQ::SOCKET_REP);
        $this->workerReplySocket->bind($this->config['sockets']['worker_reply']);
        $this->workerReplySocket->on('message', [$this, 'onWorkerReplyMessage']);
    }

    /**
     * Handles incoming client requests.
     *
     * @param array $message Json-encoded data received from client.
     */
    public function onClientMessage(array $message)
    {
        $clientAddress = $message[0];
        $data = json_decode($message[2], true);
        switch ($data['action']) {
            case 'command':
                $this->handleCommand($clientAddress, $data['command']['name']);
                break;
            case 'job':
                $this->handleJobRequest($clientAddress, $data['job']['name'], $data['job']['workload'], false);
                break;
            case 'background_job':
                $this->handleJobRequest($clientAddress, $data['job']['name'], $data['job']['workload'], true);
                break;
            default:
                $this->clientSocket->send('error: unknown action');
                break;
        }
    }

    /**
     * Handles incoming worker requests.
     *
     * @param string $message Json-encoded data received from worker.
     */
    public function onWorkerReplyMessage(string $message)
    {
        $data = json_decode($message, true);
        if (!isset($data['request'])) {
            throw new \RuntimeException('Invalid worker request received.');
        }
        $result = null;
        switch ($data['request']) {
            case 'register_job':
                $result = $this->registerJob($data['job_name'], $data['worker_id']);
                break;
            case 'unregister_job':
                $result = $this->unregisterJob($data['job_name'], $data['worker_id']);
                break;
            case 'change_state':
                $result = $this->changeWorkerState($data['worker_id'], $data['state']);
                break;
            case 'job_completed':
                $this->onJobCompleted($data['job_id'], $data['result']);
                break;
        }
        $response = ($result === true) ? 'ok' : 'error';
        $this->workerReplySocket->send($response);
    }

    /**
     * Handles data received from child processes.
     *
     * @todo Log these messages. By default workers should be silent.
     * @param string $output
     * @return bool
     */
    public function onChildProcessOut(string $output) : bool
    {
        echo "worker output: " . $output . PHP_EOL;
        return true;
    }

    /**
     * Responds to a client request.
     *
     * @param string $address
     * @param string $payload
     */
    protected function respondToClient(string $address, string $payload = '')
    {
        $clientSocket = $this->clientSocket->getWrappedSocket();
        $clientSocket->send($address, \ZMQ::MODE_SNDMORE);
        $clientSocket->send('', \ZMQ::MODE_SNDMORE);
        $clientSocket->send($payload);
    }

    /**
     * Registers a new job-type a workers is capable of doing.
     *
     * @param string $jobName
     * @param string $workerId
     * @return bool
     */
    protected function registerJob(string $jobName, string $workerId) : bool
    {
        if (!isset($this->workerJobs[$workerId])) {
            $this->workerJobs[$workerId] = [];
        }
        if (!in_array($jobName, $this->workerJobs[$workerId])) {
            array_push($this->workerJobs[$workerId], $jobName);
        }
        return true;
    }

    /**
     * Unregisters a job-type so the worker will no longer receive jobs of this type.
     *
     * @param string $jobName
     * @param string $workerId
     * @return bool
     */
    protected function unregisterJob(string $jobName, string $workerId) : bool
    {
        if (!isset($this->workerJobs[$jobName])) {
            return true;
        }
        if (($key = array_search($workerId, $this->workerJobs[$jobName])) !== false) {
            unset($this->workerJobs[$jobName][$key]);
        }
        if (empty($this->workerJobs[$jobName])) {
            unset($this->workerJobs[$jobName]);
        }
        return true;
    }

    /**
     * Changes the state a worker currently has.
     *
     * @param string $workerId
     * @param int $workerState
     * @return bool
     */
    protected function changeWorkerState(string $workerId, int $workerState) : bool
    {
        $this->workerStates[$workerId] = $workerState;
        if ($workerState === Worker::WORKER_STATE_IDLE) {
            $this->pushJobs();
        }
        return true;
    }

    /**
     * Handles a completed job. Sends results back to client if it was not a background job.
     *
     * @param string $jobId
     * @param string $result
     */
    protected function onJobCompleted(string $jobId, string $result = '')
    {
        $jobType = $this->jobTypes[$jobId];
        $clientAddress = $this->jobAddresses[$jobId];
        if ($jobType === 'normal') {
            $this->respondToClient($clientAddress, $result);
        }
        unset($this->jobTypes[$jobId], $this->jobAddresses[$jobId]);
    }

    /**
     * Executes commands received from a client.
     *
     * @param string $clientAddress
     * @param string $command
     * @return bool
     */
    protected function handleCommand(string $clientAddress, string $command) : bool
    {
        switch ($command) {
            case 'stop':
                $this->respondToClient($clientAddress, 'ok');
                $this->stop();
                return true;
            case 'status':
                $statusData = $this->getStatusData();
                $this->respondToClient($clientAddress, json_encode($statusData));
                return true;
            case 'reload':
                return true;
        }
        $this->respondToClient($clientAddress, 'error');
        return false;
    }

    /**
     * Handles job-requests received from a client.
     *
     * @param string $clientAddress
     * @param string $jobName
     * @param string $payload
     * @param bool $backgroundJob
     * @return string The id assigned to the job.
     */
    protected function handleJobRequest(
        string $clientAddress,
        string $jobName,
        string $payload = '',
        bool $backgroundJob = false
    ) : string {
        $jobId = $this->addJobToQueue($jobName, $payload);
        $this->jobTypes[$jobId] = ($backgroundJob === true) ? 'background' : 'normal';
        $this->jobAddresses[$jobId] = $clientAddress;
        $this->pushJobs();
        if ($backgroundJob === true) {
            $this->respondToClient($clientAddress, $jobId);
        }
        return $jobId;
    }

    /**
     * Adds a new job-requests to corresponding queue.
     *
     * @param string $jobName
     * @param string $payload
     * @return string The id assigned to the job.
     */
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

    /**
     * Runs trough the job queue and pushes jobs to workers if an idle worker is available.
     *
     * @return bool
     */
    protected function pushJobs() : bool
    {
        // Skip if no jobs currently in queue
        if (empty($this->jobQueues)) {
            return true;
        }

        // Run trough list of (idle) workers and check if there is a job in queue the worker can handle
        foreach ($this->workerStates as $workerId => $workerState) {
            if ($workerState !== Worker::WORKER_STATE_IDLE) {
                continue;
            }
            $jobData = $this->getJobFromQueue($workerId);
            if (empty($jobData)) {
                continue;
            }
            $this->workerStats[$workerId]++;
            $this->workerJobSocket->send($jobData['job_name'], \ZMQ::MODE_SNDMORE);
            $this->workerJobSocket->send($jobData['job_id'], \ZMQ::MODE_SNDMORE);
            $this->workerJobSocket->send($workerId, \ZMQ::MODE_SNDMORE);
            $this->workerJobSocket->send($jobData['payload']);
        }

        return true;
    }

    /**
     * Checks if there is a job in queue which the worker (identified by id) can handle.
     *
     * @param string $workerId
     * @return array Job data or empty array if no suitable job in queue.
     */
    protected function getJobFromQueue(string $workerId) : array
    {
        $workerJobs = $this->workerJobs[$workerId];
        foreach ($workerJobs as $jobName) {
            if (!isset($this->jobQueues[$jobName])) {
                continue;
            }
            if (empty($this->jobQueues[$jobName])) {
                continue;
            }
            $jobData = array_shift($this->jobQueues[$jobName]);
            $jobData['job_name'] = $jobName;
            $this->jobsInQueue--;
            return $jobData;
        }
        return [];
    }

    /**
     * Gets a job-id which is just a count of jobs converted to a hex value.
     *
     * @return string
     */
    protected function getJobId() : string
    {
        $this->jobId++;
        return dechex($this->jobId);
    }

    /**
     * Starts all worker pools defined in configuration.
     *
     * @return bool
     */
    protected function startWorkerPools() : bool
    {
        if (empty($this->config['pool'])) {
            throw new \RuntimeException('No worker pool defined. Check config file.');
        }
        foreach ($this->config['pool'] as $poolName => $poolConfig) {
            $this->startWorkerPool($poolName, $poolConfig);
        }
        return true;
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
                $this->workerStats[$workerId] = 0;
            } catch (\Exception $e) {
                // @todo Add error handling
            }
        }
    }

    /**
     * Stops processes of all pools.
     *
     * @return bool
     */
    protected function stopWorkerPools() : bool
    {
        if (empty($this->processes)) {
            return true;
        }
        foreach (array_keys($this->processes) as $poolName) {
            $this->stopWorkerPool($poolName);
        }
        return true;
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
     * Collects server and worker status information to send back to client.
     *
     * @return array
     */
    protected function getStatusData() : array
    {
        $statusData = [
            'active_worker' => [],
            'job_info' => [],
        ];
        foreach (array_keys($this->processes) as $poolName) {
            if (!isset($statusData['active_worker'][$poolName])) {
                $statusData['active_worker'][$poolName] = 0;
            }
            /** @var Process $process */
            foreach ($this->processes[$poolName] as $process) {
                $processIsRunning = $process->isRunning();
                if ($processIsRunning === true) {
                    $statusData['active_worker'][$poolName]++;
                }
            }
        }
        $statusData['job_info']['queue_length'] = $this->jobsInQueue;
        $statusData['job_info']['worker_stats'] = $this->workerStats;
        return $statusData;
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
}
