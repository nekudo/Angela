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
     * Defines a new queue to be used on broker.
     *
     * @param string $queueName
     * @return bool
     */
    public function initQueue(string $queueName) : bool;

    /**
     * Defines name of command queue and initializes this queue.
     * The command queue is used to receive commands controlling Angela.
     *
     * @param string $queueName
     * @return bool
     */
    public function setCommandQueue(string $queueName) : bool;

    /**
     * Sends a message on command queue.
     *
     * @param string $command
     */
    public function sendCommand(string  $command);

    /**
     * Fetches a message from command queue.
     *
     * @return array
     */
    public function getCommand() : array;

    /**
     * Defines name of callback queue and initializes this queue.
     * The callback queue is used to receive workers responses to jobs.
     *
     * @param string $queueName
     * @return bool
     */
    public function setCallbackQueue(string $queueName) : bool;

    /**
     * Waits for new messages on given queue and executes callback if messages are received.
     *
     * @param string $queueName
     * @param callable $callback
     */
    public function consumeQueue(string $queueName, callable $callback);

    /**
     * Confirms that a message was received.
     *
     * @param Message $message
     */
    public function ack(Message $message);

    /**
     * Rejects a received messages.
     *
     * @param Message $message
     */
    public function reject(Message $message);

    /**
     * Main loop to wait for new jobs.
     */
    public function wait();
    
    /**
     * Runs a single job and directly returns the result/response
     *
     * @param string $jobName Name of job to to. Must match queue name.
     * @param string $payload Data to send to worker.
     * @return Message Result of the job
     */
    public function doJob(string $jobName, string $payload) : Message;

    /**
     * Runs a job in background.
     *
     * @param string $jobName Name of job to to. Must match queue name.
     * @param string $payload Data to send to worker.
     * @return string A job handle
     */
    public function doBackgroundJob(string $jobName, string $payload) : string;

    /**
     * Respond to a received message/job.
     *
     * @param string $callbackId
     * @param string $response
     */
    public function respond(string $callbackId, string $response);
}
