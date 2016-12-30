<?php

/*
$context = new ZMQContext;
$socket = $context->getSocket(ZMQ::SOCKET_REQ);
$socket->connect('tcp://127.0.0.1:5551');
$socket->send('doJob');
$response = $socket->recv();
$socket->disconnect('tcp://127.0.0.1:5551');
var_dump($response);
*/

require_once __DIR__ . '/../vendor/autoload.php';

$client = new \Nekudo\Angela\Client;
$client->addServer('tcp://127.0.0.1:5551');
for ($i = 1; $i <= 30; $i++) {
    $result = $client->doNormal('taskA', 'test ' . $i);
    echo $i . ' ' . $result . PHP_EOL;
    usleep(100000);
}
$client->close();
echo 'done';
