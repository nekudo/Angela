<?php namespace Nekudo\Angela;

use Nekudo\Angela\Broker\BrokerClient;
use Nekudo\Angela\Broker\BrokerFactory;

class AngelaControl
{
    /**
     * @var array $config
     */
    protected $config;

    /**
     * @var BrokerClient $broker ;
     */
    protected $broker;

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
        $brokerFactory = new BrokerFactory($this->config['broker']);
        $this->broker = $brokerFactory->create();
    }

    /**
     * Starts Angela instance as a background process.
     */
    public function start()
    {
        $pathToAngelaScript = $this->config['script_path'];
        if (!file_exists($pathToAngelaScript)) {
            throw new \RuntimeException('Angela script not found. Check script_path in your config file.');
        }
        $phpPath = $this->config['php_path'];
        exec(escapeshellcmd($phpPath . ' ' . $pathToAngelaScript) . ' > /dev/null 2>&1 &');
    }

    /**
     * Sends "shutdown" command to Angela instance.
     *
     * @return string
     */
    public function stop() : string
    {
        /** @var \Nekudo\Angela\Broker\Message $response */
        $response = $this->broker->sendCommand('shutdown');
        if (empty($response)) {
            return '';
        }
        return $response->getBody();
    }

    public function restart()
    {

    }

    /**
     * Checks worker status of Angela instance.
     *
     * @return array
     */
    public function status() : array
    {
        /** @var \Nekudo\Angela\Broker\Message $response */
        $response = $this->broker->sendCommand('status');
        if (empty($response)) {
            return [];
        }
        $statusMessage = $response->getBody();
        return json_decode($statusMessage, true);
    }
}
