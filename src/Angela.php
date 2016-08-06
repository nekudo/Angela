<?php namespace Nekudo\Angela;

use Nekudo\Angela\Broker\BrokerClient;
use Nekudo\Angela\Broker\RabbitmqClient;
use React\ChildProcess\Process;
use React\EventLoop\Factory;

/**
 * A simple gearman worker manager.
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


    public function __construct(array $config)
    {
        if (empty($config) || empty($config['angela'])) {
            throw new \InvalidArgumentException('Configuration can not be empty.');
        }
        $this->config = $config['angela'];

        // create main event loop:
        $this->loop = Factory::create();

        // connect to message broker:
        $this->connectToBroker();

        // listen for angela control commands:
        $this->loop->addPeriodicTimer(1, [$this, 'onCommand']);
    }

    /**
     * Connects to message broker to be able to receive external commands.
     */
    public function connectToBroker()
    {
        $brokerConfig = $this->config['broker'];
        switch ($brokerConfig['type']) {
            case 'rabbitmq':
                $this->broker = new RabbitmqClient;
                $this->broker->connect($brokerConfig['credentials']);
                $this->broker->setCommandQueue($brokerConfig['queues']['cmd']);
                break;
            default:
                // @todo throw unknown broker exception
                break;
        }
    }

    protected function onProcessOut($output)
    {
        echo $output . PHP_EOL;
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
        switch ($command) {
            case 'shutdown':
                $this->stop();
                break;
            default:
                // @todo throw unknown command error
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
            throw new \RuntimeException('No worker pool defined.');
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
        if (!isset($poolConfig['worker_file'])) {
            throw new \RuntimeException('Path to worker file not set in pool config.');
        }

        $this->processes[$poolName] = [];
        $processesToStart = $poolConfig['cp_start'] ?? 5;
        for ($i = 0; $i < $processesToStart; $i++) {
            $process = new Process('exec php ' . $poolConfig['worker_file']);
            $process->start($this->loop);
            $process->stdout->on('data', function ($output) {
                $this->onProcessOut($output);
            });
            $process->stdin->write(json_encode([
                'cmd' => 'brokerConnect',
                'config' => $this->config['broker']
            ]));
            array_push($this->processes[$poolName], $process);
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
