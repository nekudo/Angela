<?php namespace Nekudo\Angela;

class AngelaControl
{
    /**
     * @var array $config
     */
    protected $config;

    public function __construct(array $config)
    {
        if (empty($config) || empty($config['angela'])) {
            throw new \InvalidArgumentException('Configuration can not be empty.');
        }
        if (!isset($config['angela']['script_path'])) {
            throw new \RuntimeException('script_path not defined in configuration.');
        }
        if (!isset($config['angela']['php_path'])) {
            throw new \RuntimeException('php_path not defined in configuration.');
        }
        $this->config = $config['angela'];
    }

    /**
     * Starts Angela instance as a background process.
     *
     * @return int Process id (0 if script could not be started).
     */
    public function start() : int
    {
        $pathToAngelaScript = $this->config['script_path'];
        if (!file_exists($pathToAngelaScript)) {
            throw new \RuntimeException('Angela script not found. Check script_path in your config file.');
        }

        // check if process is already running:
        $angelaPid = $this->getAngelaPid();
        if ($angelaPid !== 0) {
            return $angelaPid;
        }

        // startup angela:
        $phpPath = $this->config['php_path'];
        exec(escapeshellcmd($phpPath . ' ' . $pathToAngelaScript) . ' > /dev/null 2>&1 &');

        // return process id:
        $pid = $this->getAngelaPid();
        if (empty($pid)) {
            throw new \RuntimeException('Could not start Angela. (Empty Pid)');
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
        $angelaPid = $this->getAngelaPid();
        if ($angelaPid === 0) {
            return true;
        }
        $response = $this->sendCommand('shutdown');
        if ($response === 'failed') {
            throw new \RuntimeException('Shutdown failed.');
        }
        return true;
    }

    /**
     * Restarts Angela processes.
     *
     * @return int Pid of new Angela process.
     */
    public function restart() : int
    {
        $pid = $this->getAngelaPid();
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
        $angelaPid = $this->getAngelaPid();
        if (empty($angelaPid)) {
            throw new \RuntimeException('Angela not currently running.');
        }
        $response = $this->sendCommand('status');
        if (empty($response)) {
            throw new \RuntimeException('Error fetching status. (Incorrect response)');
        }
        return json_decode($response, true);
    }

    /**
     * Fetches Angela pid from process-list.
     *
     * @return int
     */
    protected function getAngelaPid() : int
    {
        $procInfo = [];
        $cliOutput = [];
        exec('ps x | grep ' . $this->config['script_path'], $cliOutput);
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
        $response = '';
        $socketAddress = 'tcp://' . $this->config['socket']['host'] . ':' . $this->config['socket']['port'];
        $stream = stream_socket_client($socketAddress, $errno, $errstr, 3);
        if ($stream === false) {
            throw new \RuntimeException('Could not connect to Angela control socket.');
        }
        fwrite($stream, $command);
        while (!feof($stream)) {
            $response .= fgets($stream, 1024);
        }
        fclose($stream);
        return $response;
    }
}
