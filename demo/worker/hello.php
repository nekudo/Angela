<?php
require_once __DIR__ . '/../../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

$read = new \React\Stream\Stream(STDIN, $loop);
$read->on('data', function ($data) use ($loop) {
    echo $data . PHP_EOL;
});

$loop->run();