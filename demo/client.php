<?php
require_once __DIR__ . '/../vendor/autoload.php';


$config = include __DIR__ . '/config.php';
$brokerFactory = new \Nekudo\Angela\Broker\BrokerFactory($config['angela']['broker']);
$brokerClient = $brokerFactory->create();
$brokerClient->setTimeout(10);

try {
    $responseA = $brokerClient->doBackgroundJob('task_a', 'just a test');
    $responseB = $brokerClient->doJob('task_b', 'just a test');
    $brokerClient->close();
    var_dump($responseA, $responseB);
} catch (\Nekudo\Angela\Exception\AngelaException $e) {
    echo $e->getMessage() . PHP_EOL;
}
