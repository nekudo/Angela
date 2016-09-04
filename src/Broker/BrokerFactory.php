<?php

namespace Nekudo\Angela\Broker;

class BrokerFactory
{
    protected $brokerConfig = [];

    public function __construct(array $brokerConfig)
    {
        $this->brokerConfig = $brokerConfig;
    }

    /**
     * Creates message-broker client.
     *
     * @return BrokerClient
     * @throws \RuntimeException
     */
    public function create() : BrokerClient
    {
        if (!isset($this->brokerConfig['type'])) {
            throw new \RuntimeException('Broker type not set in configuration.');
        }
        if (!$this->isValidBrokerType($this->brokerConfig['type'])) {
            throw new \RuntimeException('Invalid broker type set in configuration');
        }
        $broker = null;
        switch ($this->brokerConfig['type']) {
            case 'rabbitmq':
                $broker = new RabbitmqClient;
                $broker->connect($this->brokerConfig['credentials']);
                $broker->setCommandQueue($this->brokerConfig['queues']['cmd']);
                $broker->setCallbackQueue($this->brokerConfig['queues']['callback']);
                break;
        }
        return $broker;
    }

    /**
     * Checks if a broker type is supported/valid.
     *
     * @param string $brokerType
     * @return bool
     */
    protected function isValidBrokerType(string $brokerType) : bool
    {
        $validBrokerTypes = [
            'rabbitmq',
        ];
        return in_array($brokerType, $validBrokerTypes);
    }
}
