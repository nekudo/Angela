<?php
declare(strict_types=1);
namespace Nekudo\Angela;

use Nekudo\Angela\Exception\ServerException;
use Nekudo\Angela\Logger\LoggerFactory;
use Overnil\EventLoop\Factory as EventLoopFactory;
use Psr\Log\LoggerInterface;
use React\ZMQ\Context;
use React\ChildProcess\Process;

class Server
{
    const VERSION = '2.0.0';

    /**
     * Keeps the time the server was started.
     *
     * @var int $startTime
     */
    protected $startTime;

    /**
     * Holds the server configuration.
     *
     * @var array $config
     */
    protected $config;

    /**
     * Stores amount of total jobs handled by server.
     *
     * @var int $totalJobRequests
     */
    protected $totalJobRequests = 0;

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
     * @var array $workerJobCapabilities
     */
    protected $workerJobCapabilities = [];

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
     * Holds information on which worker is doing which job-id.
     *
     * @var array
     */
    protected $workerJobMap = [];

    /**
     * Simple job counter used to generate job ids.
     *
     * @var int $jobId
     */
    private $jobId = 0;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->startTime = time();

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
        try {
            $this->createClientSocket();
            $this->createWorkerJobSocket();
            $this->createWorkerReplySocket();
            $this->startWorkerPools();
            $this->loop->addPeriodicTimer(5, [$this, 'monitorChildProcesses']);
            $this->loop->addPeriodicTimer(10, [$this, 'monitorQueue']);
            $this->loop->run();
        } catch (ServerException $e) {
            $errorMsgPattern = 'Server error: %s (%s:%d)';
            $this->logger->emergency(sprintf($errorMsgPattern, $e->getMessage(), $e->getFile(), $e->getLine()));
        }
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
        try {
            $clientAddress = $message[0];
            $data = json_decode($message[2], true);
            if (empty($clientAddress) || empty($data) || !isset($data['action'])) {
                throw new ServerException('Received malformed client message.');
            }
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
                    throw new ServerException('Received request for invalid action.');
            }
        } catch (ServerException $e) {
            $errorMsgPattern = 'Client message error: %s (%s:%d)';
            $this->logger->error(sprintf($errorMsgPattern, $e->getMessage(), $e->getFile(), $e->getLine()));
        }
    }

    /**
     * Handles incoming worker requests.
     *
     * @param string $message Json-encoded data received from worker.
     * @throws ServerException
     */
    public function onWorkerReplyMessage(string $message)
    {
        try {
            $data = json_decode($message, true);
            if (empty($data) || !isset($data['request'])) {
                throw new ServerException('Invalid worker request received.');
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
                    $this->onJobCompleted($data['worker_id'], $data['job_id'], $data['result']);
                    break;
            }
            $response = ($result === true) ? 'ok' : 'error';
            $this->workerReplySocket->send($response);
        } catch (ServerException $e) {
            $errorMsgPattern = 'Worker message error: %s (%s:%d)';
            $this->logger->error(sprintf($errorMsgPattern, $e->getMessage(), $e->getFile(), $e->getLine()));
        }
    }

    /**
     * Handles data received from child processes.
     *
     * @param string $output
     */
    public function onChildProcessOut(string $output)
    {
        $this->logger->warning('Unexpected worker output: ' . $output);
    }

    /**
     * Handles "exit" signals of child processes.
     *
     * @param int $exitCode
     * @param int $termSignal
     */
    public function onChildProcessExit($exitCode, $termSignal)
    {
        $this->logger->error(sprintf('Child process exited. (Code: %d, Signal: %d)', $exitCode, $termSignal));
        $this->monitorChildProcesses();
    }

    /**
     * Responds to a client request.
     *
     * @param string $address
     * @param string $payload
     */
    protected function respondToClient(string $address, string $payload = '')
    {
        try {
            $clientSocket = $this->clientSocket->getWrappedSocket();
            $clientSocket->send($address, \ZMQ::MODE_SNDMORE);
            $clientSocket->send('', \ZMQ::MODE_SNDMORE);
            $clientSocket->send($payload);
        } catch (\ZMQException $e) {
            $this->logger->error('Error respoding to client: ' . $e->getMessage());
        }
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
        if (!isset($this->workerJobCapabilities[$workerId])) {
            $this->workerJobCapabilities[$workerId] = [];
        }
        if (!in_array($jobName, $this->workerJobCapabilities[$workerId])) {
            array_push($this->workerJobCapabilities[$workerId], $jobName);
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
        if (!isset($this->workerJobCapabilities[$jobName])) {
            return true;
        }
        if (($key = array_search($workerId, $this->workerJobCapabilities[$jobName])) !== false) {
            unset($this->workerJobCapabilities[$jobName][$key]);
        }
        if (empty($this->workerJobCapabilities[$jobName])) {
            unset($this->workerJobCapabilities[$jobName]);
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
     * @param string $workerId
     * @param string $jobId
     * @param string $result
     */
    protected function onJobCompleted(string $workerId, string $jobId, string $result = '')
    {
        $jobType = $this->jobTypes[$jobId];
        $clientAddress = $this->jobAddresses[$jobId];
        if ($jobType === 'normal') {
            $this->respondToClient($clientAddress, $result);
        }
        unset(
            $this->jobTypes[$jobId],
            $this->jobAddresses[$jobId],
            $this->workerJobMap[$workerId]
        );
    }

    /**
     * Executes commands received from a client.
     *
     * @param string $clientAddress
     * @param string $command
     * @throws ServerException
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
            case 'flush_queue':
                $result = ($this->flushQueue() === true) ? 'ok' : 'error';
                $this->respondToClient($clientAddress, $result);
                return true;

        }
        $this->respondToClient($clientAddress, 'error');
        throw new ServerException('Received unknown command.');
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
        $this->totalJobRequests++;
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
            try {
                $this->workerStats[$workerId]++;
                $this->workerJobMap[$workerId] = $jobData['job_id'];
                $this->workerJobSocket->send($jobData['job_name'], \ZMQ::MODE_SNDMORE);
                $this->workerJobSocket->send($jobData['job_id'], \ZMQ::MODE_SNDMORE);
                $this->workerJobSocket->send($workerId, \ZMQ::MODE_SNDMORE);
                $this->workerJobSocket->send($jobData['payload']);
            } catch (\ZMQException $e) {
                $this->logger->error('Error sending job to worker: ' . $e->getMessage());
            }
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
        $workerJobCapabilities = $this->workerJobCapabilities[$workerId];
        foreach ($workerJobCapabilities as $jobName) {
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
     * @throws ServerException
     */
    protected function startWorkerPools() : bool
    {
        if (empty($this->config['pool'])) {
            throw new ServerException('No worker pool defined. Check config file.');
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
     * @throws ServerException
     */
    protected function startWorkerPool(string $poolName, array $poolConfig)
    {
        if (!isset($poolConfig['worker_file'])) {
            throw new ServerException('Path to worker file not set in pool config.');
        }

        $this->processes[$poolName] = [];
        $processesToStart = $poolConfig['cp_start'] ?? 5;
        for ($i = 0; $i < $processesToStart; $i++) {
            // start child process:
            try {
                $process = $this->startChildProcess($poolConfig['worker_file']);
                $this->registerChildProcess($process, $poolName);
            } catch (\Exception $e) {
                throw new ServerException('Could not start child process.');
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
     * Checks if child processes are alive or need to be restarted.
     */
    public function monitorChildProcesses()
    {
        foreach (array_keys($this->processes) as $poolName) {
            $this->monitorPoolProcesses($poolName);
        }
    }

    /**
     * Checks state of every process in pool and restarts processes if necessary.
     *
     * @param string $poolName
     * @return bool
     */
    protected function monitorPoolProcesses(string $poolName) : bool
    {
        if (empty($this->processes[$poolName])) {
            return true;
        }
        /** @var Process $process */
        foreach ($this->processes[$poolName] as $i => $process) {
            $pid = $process->getPid();
            $workerId = $this->config['server_id'] . '_' . $pid;

            // check if process is still running:
            if ($process->isRunning() === true) {
                continue;
            }

            // if process is not running remove it from system:
            $this->unregisterChildProcess($workerId);
            unset($this->processes[$poolName][$i]);

            // fire up a new process:
            $workerFile = $this->config['pool'][$poolName]['worker_file'];
            $process = $this->startChildProcess($workerFile);
            $this->registerChildProcess($process, $poolName);
        }
        return true;
    }

    /**
     * Cleanup jobs that are not handled correctly for some reason.
     *
     * @return bool
     */
    public function monitorQueue() : bool
    {
        if ($this->jobsInQueue === 0) {
            return true;
        }
        foreach (array_keys($this->jobQueues) as $jobName) {
            if (empty($this->jobQueues[$jobName])) {
                continue;
            }
            if ($this->jobCanBeProcessed($jobName)) {
                continue;
            }
            $jobsCount = count($this->jobQueues[$jobName]);
            $this->jobQueues[$jobName] = [];
            $this->jobsInQueue -= $jobsCount;
        }
        return $this->pushJobs();
    }

    /**
     * Check if there is a least one worker capable of doing requested job type.
     *
     * @param string $jobName
     * @return bool
     */
    protected function jobCanBeProcessed(string $jobName) : bool
    {
        foreach ($this->workerJobCapabilities as $workerId => $jobs) {
            if (in_array($jobName, $jobs)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Collects server and worker status information to send back to client.
     *
     * @return array
     */
    protected function getStatusData() : array
    {
        $statusData = [
            'version' => self::VERSION,
            'starttime' => '',
            'uptime' => '',
            'active_worker' => [],
            'job_info' => [],
        ];

        $starttime = date('Y-m-d H:i:s', $this->startTime);
        $start = new \DateTime($starttime);
        $now = new \DateTime('now');
        $uptime = $start->diff($now)->format('%ad %Hh %Im %Ss');
        $statusData['starttime'] = $starttime;
        $statusData['uptime'] = $uptime;

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
        $statusData['job_info']['job_requests_total'] = $this->totalJobRequests;
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

        $process->on('exit', function ($exitCode, $termSignal) {
            $this->onChildProcessExit($exitCode, $termSignal);
        });

        return $process;
    }

    /**
     * Registers new child-process at server.
     *
     * @param Process $process
     * @param string $poolName
     * @return bool
     */
    protected function registerChildProcess(Process $process, string $poolName) : bool
    {
        array_push($this->processes[$poolName], $process);
        $workerPid = $process->getPid();
        $workerId = $this->config['server_id'] . '_' . $workerPid;
        $this->workerStates[$workerId] = Worker::WORKER_STATE_IDLE;
        $this->workerStats[$workerId] = 0;
        return true;
    }

    /**
     * Removes all references to a worker/child-process.
     *
     * @param string $workerId
     * @return bool
     */
    protected function unregisterChildProcess(string $workerId) : bool
    {
        // check if there is a client waiting for reply
        if (isset($this->workerJobMap[$workerId])) {
            $jobId = $this->workerJobMap[$workerId];
            $jobType = $this->jobTypes[$jobId];
            $clientAddress = $this->jobAddresses[$jobId];
            if ($jobType === 'normal') {
                $this->respondToClient($clientAddress, 'internal error');
            }
        }

        // unset references
        unset(
            $this->workerStates[$workerId],
            $this->workerJobCapabilities[$workerId],
            $this->workerStats[$workerId],
            $this->workerJobMap[$workerId]
        );
        return true;
    }

    /**
     * Removes jobs from all queues.
     *
     * @return bool
     */
    protected function flushQueue() : bool
    {
        foreach (array_keys($this->jobQueues) as $jobName) {
            $this->jobQueues[$jobName] = [];
        }
        $this->jobsInQueue = 0;
        return true;
    }
}
