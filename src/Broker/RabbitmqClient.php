<?php

namespace Nekudo\Angela\Broker;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

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
        return true;
    }

    /**
     * @inheritdoc
     */
    public function initQueue(string $queueName) : bool
    {
        $res = $this->channel->queue_declare($queueName, false, false, false, false);
        return (!empty($res));
    }

    public function consumeQueue(string $queueName, callable $callback)
    {
        $this->initQueue($queueName);
        $this->channel->basic_consume($queueName, '', false, false, false, false, $callback);
    }

    public function wait()
    {
        echo 'waiting...';
        $this->channel->wait(null, true);
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
    public function getCommand() : string
    {
        // @todo throw error if cmd queue name not set
        return $this->getLastMessageFromQueue($this->cmdQueueName);
    }
}
