<?php namespace Nekudo\Angela;

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
     * @var \Noodlehaus\Config $config
     */
    protected $config;

    /**
     * @var string $serverHost Hostname or IP of mq/job server.
     */
    protected $serverHost = '127.0.0.1';

    /**
     * @var int $serverPort Port of mq/job server.
     */
    protected $serverPort = 4730;

    /**
     * @var string $serverUsername Username for mq/job server.
     */
    protected $serverUsername = '';

    /**
     * @var string $serverPassword Passford for mq/job server.
     */
    protected $serverPassword = '';

    /**
     * @var string $workerPath  Path to worker files.
     */
    protected $workerPath;

    /**
     * @var string $pidPath Path to store worker pid files.
     */
    protected $runPath;

    /**
     * @var string $logPath Path to store worker log files.
     */
    protected $logPath;

    /**
     * @var string $configPath Path to configuration file.
     */
    protected $configPath;

    /**
     * @var array $startupConfig The worker startup configuration.
     */
    protected $startupConfig = [];

    /**
     * @var string $processIdentifier A string to identify worker processes in process list.
     */
    protected $processIdentifier = 'cli/launcher.php';

    /**
     * @var int $timeTillGhost Time in seconds until a worker is considered a ghost and is restarted.
     */
    protected $timeTillGhost = 1200;

    /**
     * @var array $pids Holds worker process ids.
     */
    protected $pids = [];

    public function __construct($configPath)
    {
        if (empty($configPath)) {
            throw new \InvalidArgumentException('ConfigPath can not be empty.');
        }
        $this->setConfigFromFile($configPath);
        $this->configPath = $configPath;
    }

    /**
     * Loads configuration from file.
     *
     * @param string $configPath
     * @throws \RuntimeException
     */
    public function setConfigFromFile($configPath)
    {
        if (!file_exists($configPath)) {
            throw new \RuntimeException('Configuration file not found');
        }
        $this->config = \Noodlehaus\Config::load($configPath);
        $this->checkConfig();
        $this->loadConfig();
    }

    /**
     * Checks if configuration is valid.
     *
     * @return boolean
     * @throws \RuntimeException
     */
    protected function checkConfig()
    {
        $serverCredentials = $this->config->get('angela.server');
        if (empty($serverCredentials)) {
            throw new \RuntimeException('Invalid configuration. Server block is missing.');
        }
        $workerScripts = $this->config->get('angela.workerScripts');
        if (empty($workerScripts)) {
            throw new \RuntimeException('Invalid configuration. WorkerScripts block is missing.');
        }
        return true;
    }

    /**
     * Loads required values form configuration.
     */
    protected function loadConfig()
    {
        $this->serverHost = $this->config->get('angela.server.host', '127.0.0.1');
        $this->serverPort = $this->config->get('angela.server.port', 4730);
        $this->serverUsername = $this->config->get('angela.server.user', '');
        $this->serverPassword = $this->config->get('angela.server.pass', '');
        $this->workerPath = $this->config->get('angela.workerPath', '');
        $this->runPath = $this->config->get('angela.pidPath', '');
        $this->logPath = $this->config->get('angela.logPath', '');
        $this->startupConfig = $this->config->get('angela.workerScripts', []);
        $this->timeTillGhost = $this->config->get('angela.timeTillGhost', 1200);
    }

    /**
     * Sets paths to gearman workers.
     *
     * @param string $path
     */
    public function setWorkerPath($path)
    {
        if (!file_exists($path)) {
            throw new \RuntimeException('Invalid worker path. Folder not found.');
        }
        $this->workerPath = $path;
    }

    /**
     * Sets path to log files.
     *
     * @param string $logPath
     */
    public function setLogPath($logPath)
    {
        if (!file_exists($logPath)) {
            throw new \RuntimeException('Invalid log path. Folder not found.');
        }
        $this->logPath = $logPath;
    }

    /**
     * Sets path to pid files.
     *
     * @param string $runPath
     */
    public function setRunPath($runPath)
    {
        if (!file_exists($runPath)) {
            throw new \RuntimeException('Invalid run path. Folder not found.');
        }
        $this->runPath = $runPath;
    }

    /**
     * Sets ghost time.
     *
     * @param int $ghostTime
     */
    public function setGhostTime($ghostTime)
    {
        $this->timeTillGhost = (int)$ghostTime;
    }

    /**
     * Sets worker configuration.
     *
     * @param array $workerConfig
     */
    public function setWorkerConfig(array $workerConfig)
    {
        $this->startupConfig = $workerConfig;
    }

    /**
     * Startup workers.
     *
     * @param string $typeFilter If given only workers of this type will be started.
     * @return bool
     */
    public function start($typeFilter = '')
    {
        $this->reloadPids();
        $this->pidCleanup();
        foreach ($this->startupConfig as $workerType => $workerConfig) {
            // don't start workers of different type if filter is set
            if (!empty($typeFilter) && $typeFilter !== $workerType) {
                continue;
            }

            // don't start new workers if already running:
            if (!empty($this->pids[$workerType])) {
                continue;
            }

            // startup the workers:
            for ($i = 0; $i < $workerConfig['instances']; $i++) {
                $this->startupWorker($workerType);
            }
        }
        return true;
    }

    /**
     * Stop workers.
     *
     * @param string $typeFilter If given only workers of this type will be started.
     * @return bool
     */
    public function stop($typeFilter = '')
    {
        $this->reloadPids();
        foreach ($this->startupConfig as $workerType => $workerConfig) {
            // don't stop workers of different type if filter is set
            if (!empty($typeFilter) && $typeFilter !== $workerType) {
                continue;
            }

            // skip if no worker running:
            if (empty($this->pids[$workerType])) {
                continue;
            }

            // stop the workers:
            foreach ($this->pids[$workerType] as $pid) {
                exec(escapeshellcmd('kill ' . $pid));
            }
        }
        $this->reloadPids();
        $this->pidCleanup();
        return true;
    }

    /**
     * Restart workers.
     *
     * @param string $workerId If given only workers of this type will be started.
     * @return bool
     */
    public function restart($workerId = '')
    {
        $this->stop($workerId);
        sleep(2);
        $this->start($workerId);
        return true;
    }

    /**
     * Pings every worker and displays result.
     *
     * @return array Status information.
     */
    public function status()
    {
        $this->reloadPids();

        $status = [];
        if (empty($this->pids)) {
            return $status;
        }

        $Client = new \GearmanClient();
        $Client->addServer($this->serverHost, $this->serverPort);
        $Client->setTimeout(1000);
        foreach ($this->pids as $workerPids) {
            foreach ($workerPids as $workerName => $workerPid) {
                // raises php warning on timeout so we need the "evil @" here...
                $status[$workerName] = false;
                $start = microtime(true);
                $pong = @$Client->doHigh('ping_'.$workerName, 'ping');
                if ($pong === 'pong') {
                    $jobinfo = @$Client->doHigh('jobinfo_'.$workerName, 'foo');
                    $jobinfo = json_decode($jobinfo, true);
                    $status[$workerName] = $jobinfo;
                    $pingtime = microtime(true) - $start;
                    $status[$workerName]['ping'] = $pingtime;
                }
            }
        }

        return $status;
    }

    /**
     * Pings every worker and does a "restart" if worker is not responding.
     *
     * @return bool
     */
    public function keepalive()
    {
        $this->reloadPids();

        // if already running don't do anything:
        if ($this->managerIsRunning() === true) {
            return false;
        }

        // if there are no workers at all do a fresh start:
        if (empty($this->pids)) {
            return $this->start();
        }

        // update pid-files of all workers:
        $this->updatePidFiles();

        // kill not responding workers:
        $this->killGhosts();

        // startup new workers if necessary:
        $this->adjustRunningWorkers();

        // delete old pid files:
        $this->pidCleanup();

        return true;
    }

    /**
     * Starts a new worker.
     *
     * @param $workerType
     */
    protected function startupWorker($workerType)
    {

        $workerId = $this->getId();
        $workerName = $workerType . '_' . $workerId;
        $baseCmd = 'php %s --name %s --type %s --config %s';
        $launcherPath = __DIR__ . '/cli/launcher.php';
        $startupCmd = sprintf(
            $baseCmd,
            $launcherPath,
            $workerName,
            $workerType,
            $this->configPath
        );
        exec(escapeshellcmd($startupCmd) . ' >> ' . $this->logPath . $workerType.'.log 2>&1 &');
        $this->reloadPids();
    }

    /**
     * Updates timestamp in workers pid files.
     *
     * @return bool
     */
    protected function updatePidFiles()
    {
        if (empty($this->pids)) {
            return false;
        }

        $Client = new \GearmanClient();
        $Client->addServer($this->serverHost, $this->serverPort);
        $Client->setTimeout(1000);
        foreach ($this->pids as $workerPids) {
            foreach ($workerPids as $workerName => $workerPid) {
                if (method_exists($Client, 'doHigh')) {
                    @$Client->doHigh('pidupdate_'.$workerName, 'shiny');
                } else {
                    @$Client->do('pidupdate_'.$workerName, 'shiny');
                }
            }
        }
        return true;
    }

    /**
     * Kills workers which did not update there PID file for a while.
     *
     * @return bool
     */
    protected function killGhosts()
    {
        foreach ($this->pids as $workerPids) {
            foreach ($workerPids as $workerName => $workerPid) {
                $pidFile = $this->runPath . $workerName . '.pid';
                if (!file_exists($pidFile)) {
                    throw new \RuntimeException('PID file not found.');
                }
                $lastActivity = file_get_contents($pidFile);
                $timeInactive = time() - (int)$lastActivity;
                if ($timeInactive < $this->timeTillGhost) {
                    continue;
                }
                exec(escapeshellcmd('kill ' . $workerPid));
            }
        }
        $this->reloadPids();
        return true;
    }

    /**
     * Starts up new workers if there are currently running less workers than required.
     *
     * @return bool
     */
    protected function adjustRunningWorkers()
    {
        foreach ($this->startupConfig as $workerType => $workerConfig) {
            $workersActive = count($this->pids[$workerType]);
            $workersTarget = (int)$workerConfig['instances'];
            if ($workersActive >= $workersTarget) {
                continue;
            }

            $workerDiff = $workersTarget - $workersActive;
            for ($i = 0; $i < $workerDiff; $i++) {
                $this->startupWorker($workerType);
            }
        }
        return true;
    }

    /**
     * Gets the process-ids for all workers.
     *
     * @return bool
     */
    protected function loadPids()
    {
        $cliOutput = [];
        exec('ps x | grep ' . $this->processIdentifier, $cliOutput);
        foreach ($cliOutput as $line) {
            $line = trim($line);
            $procInfo = preg_split('#\s+#', $line);
            $match = [];
            $pid = $procInfo[0];
            if (preg_match('#--name\s(.+)\s--type\s(.+)\s--#U', $line, $match) !== 1) {
                continue;
            }
            $workerName = trim($match[1]);
            $workerType = trim($match[2]);
            if (!isset($this->startupConfig[$workerType])) {
                continue;
            }

            $this->pids[$workerType][$workerName] = $pid;
        }
        return true;
    }

    /**
     * Reloads the process ids (e.g. during restart)
     *
     * @return bool
     */
    protected function reloadPids()
    {
        $this->pids = [];
        return $this->loadPids();
    }

    /**
     * Checks if an instance of worker manager is already running.
     *
     * @return bool
     */
    protected function managerIsRunning()
    {
        global $argv;
        $cliOutput = array();
        exec('ps x | grep ' . $argv[0], $cliOutput);
        $processCount = 0;
        if (empty($cliOutput)) {
            return false;
        }
        foreach ($cliOutput as $line) {
            if (strpos($line, 'grep') !== false) {
                continue;
            }
            if (strpos($line, '/bin/sh') !== false) {
                continue;
            }
            $processCount++;
        }
        return ($processCount > 1) ? true : false;
    }

    /**
     * Deletes old PID files.
     */
    protected function pidCleanup()
    {
        $pidFiles = glob($this->runPath . '*.pid');
        if (empty($pidFiles)) {
            return true;
        }
        $activeWorkerNames = [];
        foreach ($this->pids as $workerType => $typePids) {
            $activeWorkerNames = array_merge($activeWorkerNames, array_keys($typePids));
        }
        foreach ($pidFiles as $pidFilePath) {
            $filename = basename($pidFilePath, '.pid');
            if (!in_array($filename, $activeWorkerNames)) {
                unlink($pidFilePath);
            }
        }
        return true;
    }

    /**
     * Genrates a random string of given length.
     *
     * @param int $length
     * @return string
     */
    protected function getId($length = 6)
    {
        $pool = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        return substr(str_shuffle(str_repeat($pool, 5)), 0, $length);
    }
}
