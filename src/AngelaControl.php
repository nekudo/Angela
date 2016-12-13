<?php namespace Nekudo\Angela;

use Nekudo\Angela\Logger\LoggerFactory;
use Psr\Log\AbstractLogger;

class AngelaControl
{
    /**
     * @var array $config
     */
    protected $config;

    /**
     * @var AbstractLogger $logger
     */
    protected $logger;

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
        $this->initLogger();
    }

    /**
     * Sets a logger.
     *
     * @param AbstractLogger $logger
     */
    public function setLogger(AbstractLogger $logger)
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
     * Starts Angela instance as a background process.
     *
     * @return int Process id (0 if script could not be started).
     */
    public function start() : int
    {
        $this->logger->debug('Trying to start Angela main process.');
        $pathToAngelaScript = $this->config['script_path'];
        if (!file_exists($pathToAngelaScript)) {
            $this->logger->error('Angela main script not found. Check "script_path" in configuration.');
            throw new \RuntimeException('Angela script not found. Check script_path in your config file.');
        }

        // check if process is already running:
        $angelaPid = $this->getAngelaPid();
        if ($angelaPid !== 0) {
            $this->logger->debug(sprintf('Angela process already running. (Pid: %d', $angelaPid));
            return $angelaPid;
        }

        // startup angela:
        $phpPath = $this->config['php_path'];
        $startupCommand = escapeshellcmd($phpPath . ' ' . $pathToAngelaScript) . ' > /dev/null 2>&1 &';
        $this->logger->debug(sprintf('Stating angela main process. Using command %s', $startupCommand));
        exec($startupCommand);

        // return process id:
        $pid = $this->getAngelaPid();
        if (empty($pid)) {
            $this->logger->error('Could not start angela main process. Unknown error.');
            throw new \RuntimeException('Could not start Angela. (Empty Pid)');
        }
        $this->logger->debug(sprintf('Angela main process started. Pid: %d', $pid));
        return $pid;
    }

    /**
     * Sends "shutdown" command to Angela instance.
     *
     * @return bool
     */
    public function stop() : bool
    {
        $this->logger->debug('Trying to stop angela processes.');
        $angelaPid = $this->getAngelaPid();
        if ($angelaPid === 0) {
            $this->logger->debug('There seems to be no angela main process. No pid found.');
            return true;
        }
        $this->logger->debug('Sending shutdown command to angela main process.');
        $response = $this->sendCommand('shutdown');
        if ($response === 'failed') {
            $this->logger->error('Shutdown failed.');
            throw new \RuntimeException('Shutdown failed.');
        }
        $this->logger->debug('Shutdown successful.');
        return true;
    }

    /**
     * Restarts Angela processes.
     *
     * @return int Pid of new Angela process.
     */
    public function restart() : int
    {
        $this->logger->debug('Trying to restart Angela.');
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
        $this->logger->debug('Trying to request Angela status.');
        $angelaPid = $this->getAngelaPid();
        if (empty($angelaPid)) {
            $this->logger->error('No angela process found.');
            throw new \RuntimeException('Angela not currently running.');
        }
        $this->logger->debug('Sending status command to Angela main process.');
        $response = $this->sendCommand('status');
        if (empty($response)) {
            $this->logger->error('Received empty status response.');
            throw new \RuntimeException('Error fetching status. (Incorrect response)');
        }
        $this->logger->debug('Status data received.');
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
            $this->logger->error('Could not send command to Angela main process. Socket connection failed.');
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
