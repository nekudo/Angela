<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
$connection = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
$channel = $connection->channel();

/*
$channel->queue_declare('fooTask', false, false, false, false);
$msg = new AMQPMessage('some job...');
$channel->basic_publish($msg, '', 'fooTask');
*/

$channel->queue_declare('angela_cmd_1', false, false, false, false);
$msg = new AMQPMessage('shutdownxxx');
$channel->basic_publish($msg, '', 'angela_cmd_1');

echo "command send...\n";
$channel->close();
$connection->close();