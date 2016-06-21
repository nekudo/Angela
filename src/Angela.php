<?php namespace Nekudo\Angela;

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
     * @var string $logPath Path to store worker log files.
     */
    protected $logPath;

    /**
     * Holds all the worker processes.
     *
     * @var array $processes
     */
    protected $processes = [];

    /**
     * @var \React\EventLoop\ExtEventLoop|\React\EventLoop\LibEventLoop|\React\EventLoop\LibEvLoop|\React\EventLoop\StreamSelectLoop $loop
     */
    protected $loop;


    public function __construct(array $config)
    {
        if (empty($config) || empty($config['angela'])) {
            throw new \InvalidArgumentException('Configuration can not be empty.');
        }
        $this->config = $config['angela'];
        $this->loop = Factory::create();
        $this->loop->run();
    }

    /**
     * Startup workers.
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

    protected function startPool(string $poolName, array $poolConfig)
    {
        if (!isset($poolConfig['worker_file'])) {
            throw new \RuntimeException('Path to worker file not set in pool config.');
        }
        $this->processes[$poolName] = [];
        $processesToStart = $poolConfig['cp_start'] ?? 5;
        for ($i = 0; $i < $processesToStart; $i++) {
            $process = new Process('php ' . $poolConfig['worker_file']);
            $process->start($this->loop);
            array_push($this->processes[$poolName], $process);
        }
    }
}
