<?php

namespace Nekudo\Angela\Broker;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitmqClient implements BrokerClient
{
    /**
     * @var AMQPStreamConnection $connection
     */
    protected $connection;

    /**
     * @var AMQPChannel $channel
     */
    protected $channel;

    /**
     * @var string $cmdQueueName
     */
    protected $cmdQueueName = '';

    /**
     * @var string $callbackQueueName
     */
    protected $callbackQueueName = '';

    /**
     * @var array $jobs
     */
    protected $jobs = [];

    /**
     * @inheritdoc
     */
    public function connect(array $credentials) : bool
    {
        $this->connection = new AMQPStreamConnection(
            $credentials['host'],
            $credentials['port'],
            $credentials['username'],
            $credentials['password']
        );
        $this->channel = $this->connection->channel();
        $this->channel->basic_qos(null, 1, null);
        return true;
    }

    /**
     * @inheritdoc
     */
    public function close()
    {
        $this->channel->close();
        $this->connection->close();
    }

    /**
     * @inheritdoc
     */
    public function initQueue(string $queueName) : bool
    {
        $res = $this->channel->queue_declare($queueName, false, false, false, false);
        return (!empty($res));
    }

    /**
     * @inheritdoc
     */
    public function getLastMessageFromQueue(string $queueName) : string
    {
        $message = $this->channel->basic_get($queueName);
        if (empty($message)) {
            return '';
        }
        $this->channel->basic_ack($message->delivery_info['delivery_tag']);
        return $message->body;
    }

    /**
     * @inheritdoc
     */
    public function setCommandQueue(string $queueName) : bool
    {
        $this->cmdQueueName = $queueName;
        return $this->initQueue($queueName);
    }

    /**
     * @inheritdoc
     */
    public function setCallbackQueue(string $queueName) : bool
    {
        $this->callbackQueueName = $queueName;
        return $this->initQueue($queueName);
    }

    /**
     * @inheritdoc
     */
    public function consumeQueue(string $queueName, callable $callback)
    {
        $this->initQueue($queueName);
        $this->channel->basic_consume($queueName, '', false, false, false, false, $callback);
    }

    /**
     * @inheritdoc
     */
    public function wait()
    {
        while (count($this->channel->callbacks)) {
            $this->channel->wait();
        }
    }

    /**
     * Confirms message was received.
     *
     * @param AMQPMessage $message
     */
    public function ack(AMQPMessage $message)
    {
        $this->channel->basic_ack($message->delivery_info['delivery_tag']);
    }

    /**
     * @inheritdoc
     */
    public function getCommand() : string
    {
        // @todo throw error if cmd queue name not set
        return $this->getLastMessageFromQueue($this->cmdQueueName);
    }

    /**
     * @inheritdoc
     */
    public function doJob(string $jobName, string $payload) : string
    {
        $callbackId = uniqid();
        $this->consumeQueue($this->callbackQueueName, [$this, 'onJobCallback']);
        $message = new AMQPMessage(
            $payload,
            [
                'correlation_id' => $callbackId,
                'reply_to' => $this->callbackQueueName,
                'type' => 'normal',
            ]
        );
        $this->jobs[$callbackId] = false;
        $this->channel->basic_publish($message, '', $jobName);
        while ($this->hasResponse($callbackId) === false) {
            $this->channel->wait();
        }

        return $this->getResponse($callbackId);
    }

    /**
     * @inheritdoc
     */
    public function doBackgroundJob(string $jobName, string $payload) : string
    {
        $callbackId = uniqid();
        $message = new AMQPMessage(
            $payload,
            [
                'correlation_id' => $callbackId,
                'reply_to' => $this->callbackQueueName,
                'type' => 'background'
            ]
        );
        $this->jobs[$callbackId] = false;
        $this->channel->basic_publish($message, '', $jobName);
        return $callbackId;
    }

    /**
     * @inheritdoc
     */
    public function respond(string $callbackId, string $reponse)
    {
        $message = new AMQPMessage($reponse, ['correlation_id' => $callbackId]);
        $this->channel->basic_publish($message, '', $this->callbackQueueName);
    }

    /**
     * Listens for messages on callback queue and handles them if callbackId is known.
     *
     * @param AMQPMessage $message
     * @return bool
     */
    public function onJobCallback(AMQPMessage $message) : bool
    {
        // Check if job is known
        $callbackId = $message->get('correlation_id');
        if (!isset($this->jobs[$callbackId])) {
            return false;
        }

        // If job is known store the response:
        $this->jobs[$callbackId] = $message->getBody();
        $this->ack($message);
        return true;
    }

    /**
     * Checks if response exists for given callbackId.
     *
     * @param string $callbackId
     * @return bool
     */
    protected function hasResponse(string $callbackId) : bool
    {
        if (!isset($this->jobs[$callbackId])) {
            throw new \RuntimeException('Invalid callback id. Can not check response of unknown job.');
        }
        return ($this->jobs[$callbackId] !== false);
    }

    /**
     * Fetches response for given callbackId from jobs array.
     *
     * @param string $callbackId
     * @return string
     */
    protected function getResponse(string $callbackId) : string
    {
        if (!isset($this->jobs[$callbackId])) {
            throw new \RuntimeException('Unknown callback id.');
        }
        return $this->jobs[$callbackId];
    }
}
