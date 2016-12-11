<?php
require_once __DIR__ . '/../vendor/autoload.php';


$config = include __DIR__ . '/config.php';


/*
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
*/


/*
$loop = React\EventLoop\Factory::create();
$client = stream_socket_client('tcp://127.0.0.1:1338');
$conn = new React\Stream\Stream($client, $loop);
//$conn->pipe(new React\Stream\Stream(STDOUT, $loop));
$response = '';
$conn->on('data', function ($data) use ($conn, &$response) {
    $response = $data;
});
$conn->write("shutdown");
$loop->run();
var_dump($response);

*/


$fp = stream_socket_client('tcp://127.0.0.1:1338');
fwrite($fp, "shutdown");
while (!feof($fp)) {
    echo fgets($fp, 1024);
}
fclose($fp);
