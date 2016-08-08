<?php

namespace Nekudo\Angela\Broker;

class BrokerFactory
{
    protected $brokerConfig = [];

    public function __construct(array $brokerConfig)
    {
        $this->brokerConfig = $brokerConfig;
    }

    public function create()
    {
        switch ($this->brokerConfig['type']) {
            case 'rabbitmq':
                $broker = new RabbitmqClient;
                $broker->connect($this->brokerConfig['credentials']);
                $broker->setCommandQueue($this->brokerConfig['queues']['cmd']);
                return $broker;
                break;
            default:
                // @todo throw unknown broker exception
                break;
        }
    }
}
