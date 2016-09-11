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
     * @var array $queueCallbacks
     */
    protected $queueCallbacks = [];

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
    public function setCommandQueue(string $queueName) : bool
    {
        $this->cmdQueueName = $queueName;
        return $this->initQueue($queueName);
    }

    /**
     * @inheritdoc
     */
    public function sendCommand(string $command)
    {
        return $this->doJob($this->cmdQueueName, $command);
    }

    /**
     * @inheritdoc
     */
    public function getCommand() : array
    {
        if (empty($this->cmdQueueName)) {
            throw new \RuntimeException('Can not fetch command. Command queue name not set.');
        }
        $message = $this->channel->basic_get($this->cmdQueueName);
        if (empty($message)) {
            return [];
        }
        $angelaMessage = $this->convertMessage($message);
        $this->ack($angelaMessage);
        return [
            'command' => $angelaMessage->getBody(),
            'callbackId' => $angelaMessage->getCallbackId()
        ];
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
        $this->queueCallbacks[$queueName] = $callback;
        $this->channel->basic_consume($queueName, '', false, false, false, false, [$this, 'onConsumeCallback']);
    }

    /**
     * This method handles all callback messages for queues consumed by the client.
     * Received messages are converted to AngelaMessages and than passed to the worker callback methods.
     *
     * @param AMQPMessage $message
     */
    public function onConsumeCallback(AMQPMessage $message)
    {
        $task = (string) $message->delivery_info['routing_key'];
        $angelaMessage = $this->convertMessage($message);
        call_user_func($this->queueCallbacks[$task], $angelaMessage);
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
     * @inheritdoc
     */
    public function ack(Message $message)
    {
        $this->channel->basic_ack($message->getMessageId());
    }

    /**
     * @inheritdoc
     */
    public function doJob(string $jobName, string $payload) : Message
    {
        $callbackId = uniqid();
        $this->consumeQueue($this->callbackQueueName, [$this, 'onJobCallback']);
        $message = new AMQPMessage(
            $payload,
            [
                'correlation_id' => $callbackId,
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
     * @param Message $message
     * @return bool
     */
    public function onJobCallback(Message $message) : bool
    {
        // Check if job is known
        $callbackId = $message->getCallbackId();
        if (!isset($this->jobs[$callbackId])) {
            return false;
        }

        // If job is known store the response:
        $this->jobs[$callbackId] = $message;
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
     * @return Message
     */
    protected function getResponse(string $callbackId) : Message
    {
        if (!isset($this->jobs[$callbackId])) {
            throw new \RuntimeException('Unknown callback id.');
        }
        return $this->jobs[$callbackId];
    }

    /**
     * Converts AMQP message to simple Angela message.
     *
     * @param AMQPMessage $message
     * @return Message
     */
    protected function convertMessage(AMQPMessage $message) : Message
    {
        $angelaMessage = new Message;
        $angelaMessage->setMessageId($message->delivery_info['delivery_tag']);
        $angelaMessage->setCallbackId($message->get('correlation_id'));
        if ($message->has('type')) {
            $angelaMessage->setType($message->get('type'));
        }
        $angelaMessage->setBody($message->body);
        return $angelaMessage;
    }
}
