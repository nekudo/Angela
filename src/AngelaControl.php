<?php namespace Nekudo\Angela;

use Nekudo\Angela\Exception\ClientException;
use Nekudo\Angela\Exception\ControlException;

class AngelaControl
{
    /**
     * @var array $config
     */
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Starts Server instance as a background process.
     *
     * @throws ControlException
     * @return int Process id (0 if script could not be started).
     */
    public function start() : int
    {
        $pathToServerScript = $this->config['server_path'];
        if (!file_exists($pathToServerScript)) {
            throw new ControlException('Server script not found. Check server_path in your config file.');
        }

        // check if process is already running:
        $serverPid = $this->getServerPid();
        if ($serverPid !== 0) {
            return $serverPid;
        }

        // startup server:
        $phpPath = $this->config['php_path'];
        $startupCommand = escapeshellcmd($phpPath . ' ' . $pathToServerScript) . ' > /dev/null 2>&1 &';
        exec($startupCommand);

        // return process id:
        $pid = $this->getServerPid();
        if (empty($pid)) {
            throw new ControlException('Could not start server. (Empty Pid)');
        }
        return $pid;
    }

    /**
     * Sends "shutdown" command to Angela instance.
     *
     * @throws ControlException
     * @return bool
     */
    public function stop() : bool
    {
        $serverPid = $this->getServerPid();
        if ($serverPid === 0) {
            return true;
        }
        $response = $this->sendCommand('stop');
        if ($response !== 'ok') {
            throw new ControlException('Shutdown failed.');
        }
        return true;
    }

    /**
     * Restarts Angela processes.
     *
     * @throws ControlException
     * @return int Pid of new server process.
     */
    public function restart() : int
    {
        $pid = $this->getServerPid();
        if (empty($pid)) {
            return $this->start();
        }
        $stopped = $this->stop();
        if ($stopped !== true) {
            throw new ControlException('Error while stopping current Angela process.');
        }
        return $this->start();
    }

    /**
     * Checks worker status of Angela instance.
     *
     * @throws ControlException
     * @return array
     */
    public function status() : array
    {
        $serverPid = $this->getServerPid();
        if (empty($serverPid)) {
            throw new ControlException('Angela not currently running.');
        }
        $response = $this->sendCommand('status');
        if (empty($response)) {
            throw new ControlException('Error fetching status. (Incorrect response)');
        }
        return json_decode($response, true);
    }

    /**
     * Flushes all job queue in server instance.
     *
     * @throws ControlException
     * @return bool
     */
    public function flushQueue() : bool
    {
        $serverPid = $this->getServerPid();
        if (empty($serverPid)) {
            throw new ControlException('Angela not currently running.');
        }
        $response = $this->sendCommand('flush_queue');
        if ($response !== 'ok') {
            throw new ControlException('Error flushing queue. (Incorrect response)');
        }
        return true;
    }

    /**
     * Tries to kill all Angela related processes.
     *
     * @return bool
     */
    public function kill() : bool
    {
        // kill server process:
        $serverPid = $this->getServerPid();
        if (!empty($serverPid)) {
            $this->killProcessById($serverPid);
        }

        // kill worker processes:
        $workerPids = [];
        foreach ($this->config['pool'] as $poolName => $poolConfig) {
            $pids = $this->getPidsByPath($poolConfig['worker_file']);
            if (empty($pids)) {
                continue;
            }
            $workerPids = array_merge($workerPids, $pids);
        }
        if (empty($workerPids)) {
            return true;
        }
        foreach ($workerPids as $pid) {
            $this->killProcessById($pid);
        }
        return true;
    }

    /**
     * Tries to estimate PID of server process.
     *
     * @return int
     */
    protected function getServerPid() : int
    {
        return $this->getPidByPath($this->config['server_path']);
    }

    /**
     * Tries to estimate PID by given path.
     *
     * @param string $path
     * @return int
     */
    protected function getPidByPath(string $path) : int
    {
        $procInfo = [];
        $cliOutput = [];
        exec('ps x | grep ' . $path, $cliOutput);
        foreach ($cliOutput as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            if (strpos($line, 'grep') !== false) {
                continue;
            }
            $procInfo = preg_split('#\s+#', $line);
            break;
        }
        if (empty($procInfo)) {
            return 0;
        }
        return (int)$procInfo[0];
    }

    /**
     * Tries to estimate all PIDs by given path.
     *
     * @param string $path
     * @return array
     */
    protected function getPidsByPath(string $path) : array
    {
        $pids = [];
        $cliOutput = [];
        exec('ps x | grep ' . $path, $cliOutput);
        foreach ($cliOutput as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            if (strpos($line, 'grep') !== false) {
                continue;
            }
            $procInfo = preg_split('#\s+#', $line);
            array_push($pids, (int)$procInfo[0]);
        }
        return $pids;
    }

    /**
     * Kills a process by given PID.
     *
     * @param int $pid
     * @return bool
     */
    protected function killProcessById(int $pid)
    {
        $result = exec('kill ' . $pid);
        return empty($result);
    }

    /**
     * Sends a control command to Angela main process using socket connection.
     *
     * @param string $command
     * @return string
     */
    protected function sendCommand(string $command) : string
    {
        try {
            $client = new Client;
            $client->addServer($this->config['sockets']['client']);
            $response = $client->sendCommand($command);
            $client->close();
            return $response;
        } catch (ClientException $e) {
            return 'error';
        }
    }
}
