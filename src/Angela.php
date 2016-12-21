<?php namespace Nekudo\Angela;

use Nekudo\Angela\Logger\LoggerFactory;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use React\ChildProcess\Process;
use Overnil\EventLoop\Factory as EventLoopFactory;
use React\Socket\Connection;
use React\Socket\Server;

/**
 * A microservice/worker framework.
 *
 * @author Simon Samtleben <simon@nekudo.com>
 * @license https://github.com/nekudo/Angela/blob/master/LICENSE MIT
 *
 */
class Angela
{
    /**
     * @var array $config
     */
    protected $config;

    /**
     * Holds all the worker processes.
     *
     * @var array $processes
     */
    protected $processes = [];

    /**
     * @var \React\EventLoop\ExtEventLoop|\React\EventLoop\LibEventLoop
     * |\React\EventLoop\LibEvLoop|\React\EventLoop\StreamSelectLoop $loop
     */
    protected $loop;

    /**
     * @var Server $socket
     */
    protected $socket;

    /**
     * @var AbstractLogger $logger
     */
    protected $logger;


    public function __construct(array $config)
    {
        if (empty($config) || empty($config['angela'])) {
            throw new \InvalidArgumentException('Configuration can not be empty.');
        }
        $this->config = $config['angela'];

        $this->initLogger();

        // create main event loop:
        $this->logger->debug('Creating main event loop.');
        $this->loop = EventLoopFactory::create();

        // start/open socket
        $this->startSocketServer();

        // periodically check workers:
        $this->loop->addPeriodicTimer(5, [$this, 'checkWorkerStatus']);
    }

    /**
     * Sets a logger.
     *
     * @param AbstractLogger $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Initializes logger as defined in configuration.
     */
    protected function initLogger()
    {
        $loggerFactory = new LoggerFactory($this->config['logger']);
        $logger = $loggerFactory->create();
        $this->setLogger($logger);
    }

    /**
     * Opens a socket to listen for control commands.
     */
    protected function startSocketServer()
    {
        $this->logger->debug(
            sprintf(
                'Starting socket server. (Host: %s, Port: %d)',
                $this->config['socket']['port'],
                $this->config['socket']['host']
            )
        );
        $this->socket = new Server($this->loop);
        $this->socket->on('connection', function ($connection) {
            /** @var \React\Socket\Connection $connection */
            $connection->on('data', function ($data) use ($connection) {
                $this->onCommand($data, $connection);
            });
        });
        $this->socket->listen($this->config['socket']['port'], $this->config['socket']['host']);
    }

    /**
     * Executes control-commands.
     *
     * @param string $data
     * @param Connection $connection
     * @return bool
     */
    public function onCommand($data, Connection $connection) : bool
    {
        $this->logger->debug(sprintf('Received data via socket connection. Data: %s', $data));
        switch ($data) {
            case 'shutdown':
                $connection->end('success');
                $this->stop();
                return true;
            case 'status':
                $statusData = $this->getStatus();
                $connection->end(json_encode($statusData));
                return true;
            default:
                $this->logger->debug('Received unknown command on via socket connection.');
                return false;
        }
    }

    /**
     * Handles data received from child processes.
     *
     * @param string $output
     * @return bool
     */
    protected function onProcessOut(string $output) : bool
    {
        if (empty($output)) {
            $this->logger->debug('Received empty message from child process.');
            return true;
        }
        $this->logger->info(sprintf('Received output from child process: %s'. $output));
        return true;
    }

    /**
     * Startup worker processes and run main loop.
     */
    public function start()
    {
        if (empty($this->config['pool'])) {
            $this->logger->error('No worker pool defined. Check "pool" section in configuration.');
            throw new \RuntimeException('No worker pool defined in configuration.');
        }

        // fire up processes:
        foreach ($this->config['pool'] as $poolName => $poolConfig) {
            $this->startPool($poolName, $poolConfig);
        }

        // run
        $this->logger->debug('Starting main event loop.');
        $this->loop->run();
    }

    /**
     * Stop all child processes (workers) and stop main loop.
     */
    protected function stop()
    {
        // stop worker pools
        foreach ($this->config['pool'] as $poolName => $poolConfig) {
            $this->stopPool($poolName);
        }

        // stop main loop
        $this->logger->debug('Stopping main event loop.');
        $this->loop->stop();
    }

    /**
     * Counts active processes in each pool.
     *
     * @return array
     */
    protected function getStatus() : array
    {
        $this->logger->debug('Collecting status data of child processes.');
        $statusData = [];
        foreach (array_keys($this->processes) as $poolName) {
            if (!isset($statusData[$poolName])) {
                $statusData[$poolName] = 0;
            }
            foreach ($this->processes[$poolName] as $process) {
                /** @var Process $process */
                if ($process->isRunning() !== false) {
                    $statusData[$poolName]++;
                }
            }
        }
        return $statusData;
    }

    /**
     * Checks if worker processes are still running.
     */
    public function checkWorkerStatus()
    {
        $this->logger->debug('Checking worker status.');
        foreach ($this->config['pool'] as $poolName => $poolConfig) {
            $this->checkPoolStatus($poolName);
        }
    }

    /**
     * Checks if all processes in a pool are still running. If not processes are restarted.
     *
     * @param string $poolName
     */
    protected function checkPoolStatus(string $poolName)
    {
        $poolConfig = $this->config['pool'][$poolName];
        foreach ($this->processes[$poolName] as $i => $process) {
            /** @var Process $process */
            if ($process->isRunning() === false) {
                $this->logger->info(sprintf('Found process not running. Stating new process. (Pool: %s)', $poolName));
                unset($process, $this->processes[$poolName][$i]);
                $process = $this->startProcess($poolConfig['worker_file']);
                $this->processes[$poolName][$i] = $process;
            }
        }
    }

    /**
     * Starts child processes as defined in pool configuration.
     *
     * @param string $poolName
     * @param array $poolConfig
     */
    protected function startPool(string $poolName, array $poolConfig)
    {
        $this->logger->debug(sprintf('Starting processes of pool %s.', $poolName));
        if (!isset($poolConfig['worker_file'])) {
            $this->logger->error('Worker file not defined in pool configuration.');
            throw new \RuntimeException('Path to worker file not set in pool config.');
        }

        $this->processes[$poolName] = [];
        $processesToStart = $poolConfig['cp_start'] ?? 5;
        for ($i = 0; $i < $processesToStart; $i++) {
            // start child process:
            try {
                $process = $this->startProcess($poolConfig['worker_file']);
                array_push($this->processes[$poolName], $process);
            } catch (\Exception $e) {
                $this->logger->warning(sprintf('Could not start child process. (Error: s)', $e->getMessage()));
            }
        }
    }

    /**
     * Terminates all child processes of given pool.
     *
     * @param string $poolName
     * @return bool
     */
    protected function stopPool(string $poolName) : bool
    {
        $this->logger->debug(sprintf('Stopping processes of pool %s', $poolName));
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
    protected function startProcess(string $pathToFile) : Process
    {
        $startupCommand = 'exec php ' . $pathToFile;
        $this->logger->debug(sprintf('Stating child process using command: %s', $startupCommand));
        $process = new Process($startupCommand);
        $process->start($this->loop);

        // listen to output from child process:
        $process->stdout->on('data', function ($output) {
            $this->onProcessOut($output);
        });

        // init child process and inject config:
        $process->stdin->write(json_encode([
            'cmd' => 'init',
            'data' => $this->config,
        ]));

        return $process;
    }
}
