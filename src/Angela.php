<?php namespace Nekudo\Angela;

use Katzgrau\KLogger\Logger;
use Nekudo\Angela\Broker\BrokerClient;
use Nekudo\Angela\Broker\BrokerFactory;
use Nekudo\Angela\Logger\LoggerFactory;
use React\ChildProcess\Process;
use React\EventLoop\Factory as LoopFactory;

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
     * @var BrokerClient $broker;
     */
    protected $broker;

    /**
     * @var Logger $logger
     */
    protected $logger;


    public function __construct(array $config)
    {
        if (empty($config) || empty($config['angela'])) {
            throw new \InvalidArgumentException('Configuration can not be empty.');
        }
        $this->config = $config['angela'];

        $loggerFactory = new LoggerFactory($this->config['logger']);
        $this->logger = $loggerFactory->create();
        unset($loggerFactory);
        $this->logger->debug('Logger initialized');

        // create main event loop:
        $this->loop = LoopFactory::create();

        // connect to message broker:
        $this->connectToBroker();

        // listen for angela control commands:
        $this->loop->addPeriodicTimer(0.5, [$this, 'onCommand']);
    }

    /**
     * Connects to message broker to be able to receive external commands.
     */
    public function connectToBroker()
    {
        $this->logger->debug('Connecting to message broker');
        $brokerFactory = new BrokerFactory($this->config['broker']);
        $this->broker = $brokerFactory->create();
        unset($brokerFactory);
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
            return true;
        }
        $this->logger->debug('Child process output: ' . $output);
        return true;
    }

    /**
     * Checks message broker for new command and calls action if command is received.
     *
     * @return bool
     */
    public function onCommand() : bool
    {
        $command = $this->broker->getCommand();
        if (empty($command)) {
            return true;
        }
        $this->logger->debug('Received command from broker: ' . $command);
        switch ($command) {
            case 'shutdown':
                $this->stop();
                break;
            default:
                $this->logger->warning('Received invalid command on command queue. Command received: ' . $command);
                break;
        }
        return true;
    }

    /**
     * Startup worker processes and run main loop.
     *
     * @return bool
     */
    public function start()
    {
        if (empty($this->config['pool'])) {
            throw new \RuntimeException('No worker pool defined in configuration.');
        }

        // fire up processes:
        foreach ($this->config['pool'] as $poolName => $poolConfig) {
            $this->startPool($poolName, $poolConfig);
        }

        // run
        $this->loop->run();
    }

    /**
     * Stop all child processes (workers) and stop main loop.
     */
    public function stop()
    {
        // stop worker pools
        foreach ($this->config['pool'] as $poolName => $poolConfig) {
            $this->stopPool($poolName);
        }

        // stop main loop
        $this->loop->stop();
    }

    /**
     * Starts child processes as defined in pool configuration.
     *
     * HINT: We need to prepend php command with "exec" to avoid sh-wrapper.
     * @see https://github.com/symfony/symfony/issues/5759
     *
     * @param string $poolName
     * @param array $poolConfig
     */
    protected function startPool(string $poolName, array $poolConfig)
    {
        $this->logger->debug('Starting pool ' . $poolName);
        if (!isset($poolConfig['worker_file'])) {
            throw new \RuntimeException('Path to worker file not set in pool config.');
        }

        $this->processes[$poolName] = [];
        $processesToStart = $poolConfig['cp_start'] ?? 5;
        for ($i = 0; $i < $processesToStart; $i++) {
            // start child process:
            try {
                $process = new Process('exec php ' . $poolConfig['worker_file']);
                $process->start($this->loop);

                // listen to output from child processs:
                $process->stdout->on('data', function ($output) {
                    $this->onProcessOut($output);
                });

                // send command to connect to broker:
                $process->stdin->write(json_encode([
                    'cmd' => 'broker:connect',
                    'config' => $this->config['broker']
                ]));

                array_push($this->processes[$poolName], $process);
            } catch (\Exception $e) {
                $this->logger->critical('Could not start worker process. Exception: ' . $e->getMessage());
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
        if (empty($this->processes[$poolName])) {
            return true;
        }
        foreach ($this->processes[$poolName] as $process) {
            /** @var Process $process */
            $process->terminate();
        }
        return true;
    }
}
