<?php

require_once __DIR__ . '/../vendor/autoload.php';

try {
    $start = microtime(true);

    $client = new \Nekudo\Angela\Client;
    $client->addServer('tcp://127.0.0.1:5551');

    for ($i = 1; $i <= 100; $i++) {
        if (rand(1, 2) === 1) {
            $result = $client->doNormal('taskA', 'job_' . $i);
        } else {
            $result = $client->doBackground('taskA', 'job_' . $i);
        }
        echo 'job_' . $i . ' -> ' . $result . PHP_EOL;
    }
    $client->close();

    $end = microtime(true);
    $duration = $end - $start;

    echo 'done in ' . $duration . 's' . PHP_EOL;
} catch (\Nekudo\Angela\Exception\ClientException $e) {
    echo $e->getMessage() . PHP_EOL;
}
