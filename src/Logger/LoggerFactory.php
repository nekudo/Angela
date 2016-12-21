<?php

namespace Nekudo\Angela\Logger;

use Katzgrau\KLogger\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class LoggerFactory
{
    protected $config = [];

    public function __construct(array $loggerConfig)
    {
        $this->config = $loggerConfig;
    }

    /**
     * Creates logger depending on configuration.
     *
     * @return LoggerInterface
     */
    public function create() : LoggerInterface
    {
        $loggerType = $this->config['type'] ?? 'null';
        switch ($loggerType) {
            case 'file':
                return new Logger($this->config['path'], $this->config['level']);
            case 'null':
                return new NullLogger;
            default:
                throw new \RuntimeException('Invalid logger type');
        }
    }
}
