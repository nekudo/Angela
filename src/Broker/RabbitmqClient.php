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
     * @var AMQPChannel $channel;
     */
    protected $channel;

    public function connect(array $credentials)
    {
        $this->connection = new AMQPStreamConnection(
            $credentials['host'],
            $credentials['port'],
            $credentials['username'],
            $credentials['password']
        );

        $this->channel = $this->connection->channel();
    }

    public function initQueue(string $queueName) : bool
    {
        $res = $this->channel->queue_declare($queueName, false, false, false, false);
        return (!empty($res));
    }

    public function getLastMessageFromQueue(string $queueName) : string
    {
        $message = $this->channel->basic_get($queueName);
        if (empty($message)) {
            return '';
        }
        return $message->body;
    }
}
