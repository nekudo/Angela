<?php

namespace Nekudo\Angela\Demo;

use Nekudo\Angela\Worker;

require_once __DIR__ . '/../../vendor/autoload.php';

class WorkerA extends Worker
{
    public function taskA(string $payload)
    {
        echo 'hi this is workerA. I am executing taskA...';
    }
}

$worker = new WorkerA;
$worker->registerJob('taskA', [$worker, 'taskA']);
$worker->run();
