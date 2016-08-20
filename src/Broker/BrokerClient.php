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

    
    /**
     * Runs a single job and directly returns the result/response
     *
     * @param string $jobName Name of job to to. Must match queue name.
     * @param string $payload Data to send to worker.
     * @return string Result of the job
     */
    public function do(string $jobName, string $payload) : string;

    /**
     * Runs a job in background.
     *
     * @param string $jobName Name of job to to. Must match queue name.
     * @param string $payload Data to send to worker.
     * @return string A job handle
     */
    public function doBackground(string $jobName, array $payload) : string;
}
