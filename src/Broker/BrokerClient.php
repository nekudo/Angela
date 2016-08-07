<?php

namespace Nekudo\Angela\Broker;

interface BrokerClient
{
    /**
     * Connects to message broker.
     *
     * @param array $credentials
     * @return bool
     */
    public function connect(array $credentials) : bool;

    /**
     * Defines a new queue to be used on broker.
     *
     * @param string $queueName
     * @return bool
     */
    public function initQueue(string $queueName) : bool;

    /**
     * Fetches last message from queue.
     *
     * @param string $queueName
     * @return string
     */
    public function getLastMessageFromQueue(string $queueName) : string;

    /**
     * Defines name of command queue and inits this queue.
     * The command queue is used to receive commands controlling Angela.
     *
     * @param string $queueName
     * @return bool
     */
    public function setCommandQueue(string $queueName) : bool;

    /**
     * Fetches a message from command queue.
     *
     * @return string
     */
    public function getCommand() : string;
}
