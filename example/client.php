<?php

require_once __DIR__ . '/../vendor/autoload.php';

$start = microtime(true);

$client = new \Nekudo\Angela\Client;
$client->addServer('tcp://127.0.0.1:5551');

for ($i = 1; $i <= 500; $i++) {
    //$result = $client->doNormal('taskA', 'job_' . $i);
    $result = $client->doBackground('taskA', 'job_' . $i);
    echo 'job_'.$i . ' -> ' . $result . PHP_EOL;
}
$client->close();

$end = microtime(true);
$duration = $end - $start;

echo 'done in ' . $duration.'s' . PHP_EOL;
