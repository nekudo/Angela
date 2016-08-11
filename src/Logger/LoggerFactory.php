<?php

namespace Nekudo\Angela\Logger;

use Katzgrau\KLogger\Logger;

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
     * @return Logger
     */
    public function create() : Logger
    {
        if (!isset($this->loggerConfig['path'])) {
            throw new \RuntimeException('Path for logfiles not set in configuration.');
        }
        if (!isset($this->loggerConfig['level'])) {
            $this->loggerConfig['level'] = 'warning';
        }
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
