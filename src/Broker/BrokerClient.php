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
     * Closes connection to message broker.
     *
     * @return mixed
     */
    public function close();

    /**
     * Sends a message on command queue.
     *
     * @param string $command
     */
    public function sendCommand(string  $command);
}
