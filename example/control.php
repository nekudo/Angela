<?php

$context = new ZMQContext;
$socket = $context->getSocket(ZMQ::SOCKET_REQ);
$socket->connect('tcp://127.0.0.1:5551');
$socket->send('stop');
$response = $socket->recv();
$socket->disconnect('tcp://127.0.0.1:5551');
var_dump($response);
