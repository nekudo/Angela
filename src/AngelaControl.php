<?php namespace Nekudo\Angela;

class AngelaControl
{
    // @todo Implement "force kill"
    // @todo Implement "clear queue"

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
     * @return int Process id (0 if script could not be started).
     */
    public function start() : int
    {

        $pathToServerScript = $this->config['server_path'];
        if (!file_exists($pathToServerScript)) {
            throw new \RuntimeException('Server script not found. Check server_path in your config file.');
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
            throw new \RuntimeException('Could not start server. (Empty Pid)');
        }
        return $pid;
    }

    /**
     * Sends "shutdown" command to Angela instance.
     *
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
            throw new \RuntimeException('Shutdown failed.');
        }
        return true;
    }

    /**
     * Restarts Angela processes.
     *
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
            throw new \RuntimeException('Error while stopping current Angela process.');
        }
        return $this->start();
    }

    /**
     * Checks worker status of Angela instance.
     *
     * @return array
     */
    public function status() : array
    {
        $serverPid = $this->getServerPid();
        if (empty($serverPid)) {
            throw new \RuntimeException('Angela not currently running.');
        }
        $response = $this->sendCommand('status');
        if (empty($response)) {
            throw new \RuntimeException('Error fetching status. (Incorrect response)');
        }
        return json_decode($response, true);
    }

    public function flushQueue() : bool
    {
        $serverPid = $this->getServerPid();
        if (empty($serverPid)) {
            throw new \RuntimeException('Angela not currently running.');
        }
        $response = $this->sendCommand('flush_queue');
        if ($response !== 'ok') {
            throw new \RuntimeException('Error flushing queue. (Incorrect response)');
        }
        return true;
    }

    /**
     * Fetches Angela pid from process-list.
     *
     * @return int
     */
    protected function getServerPid() : int
    {
        $procInfo = [];
        $cliOutput = [];
        exec('ps x | grep ' . $this->config['server_path'], $cliOutput);
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
     * Sends a control command to Angela main process using socket connection.
     *
     * @param string $command
     * @return string
     */
    protected function sendCommand(string $command) : string
    {
        $client = new Client;
        $client->addServer($this->config['sockets']['client']);
        $response = $client->sendCommand($command);
        $client->close();
        return $response;
    }
}
