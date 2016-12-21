<?php
declare(strict_types=1);
namespace Nekudo\Angela;

use Nekudo\Angela\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

class Server
{
    /**
     * @var LoggerInterface $logger
     */
    protected $logger;

    public function __construct(array $config)
    {
        // create logger
        $loggerFactory = new LoggerFactory($config['logger']);
        $logger = $loggerFactory->create();
        $this->setLogger($logger);
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
}
