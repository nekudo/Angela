<?php

namespace Nekudo\Angela\Broker;

interface BrokerClient
{
    public function connect(array $credentials);

    public function initQueue(string $queueName) : bool;

    public function getLastMessageFromQueue(string $queueName);
}
