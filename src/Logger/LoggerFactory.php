<?php

namespace Nekudo\Angela\Logger;

use Katzgrau\KLogger\Logger;
use Psr\Log\AbstractLogger;

class LoggerFactory
{
    protected $loggerConfig = [];

    public function __construct(array $loggerConfig)
    {
        $this->loggerConfig = $loggerConfig;
    }

    /**
     * Creates logger depending on configuration.
     *
     * @return AbstractLogger
     */
    public function create() : AbstractLogger
    {
        if (!isset($this->loggerConfig['path'])) {
            throw new \RuntimeException('Path for logfiles not set in configuration.');
        }
        $this->loggerConfig['level'] = $this->loggerConfig['level'] ?? 'warning';
        if (!$this->isValidLogLevel($this->loggerConfig['level'])) {
            $this->loggerConfig['level'] = 'warning';
        }
        return new Logger($this->loggerConfig['path'], $this->loggerConfig['level']);
    }

    /**
     * Checks if given string is a valid PSR-3 log level.
     *
     * @param string $logLevel
     * @return bool
     */
    protected function isValidLogLevel(string $logLevel) : bool
    {
        $psrLevels = [
            'emergency',
            'alert',
            'critical',
            'error',
            'warning',
            'notice',
            'info',
            'debug'
        ];
        return in_array($logLevel, $psrLevels);
    }
}
