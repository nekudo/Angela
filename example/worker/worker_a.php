<?php

namespace Nekudo\Angela\Demo;

use Nekudo\Angela\Worker;

require_once __DIR__ . '/../../vendor/autoload.php';

class WorkerA extends Worker
{
    public function taskA(string $payload)
    {
        //echo "worker " . $this->workerId . ' doing taskA with payload: ' . $payload . PHP_EOL;
        sleep(rand(1, 3));
    }
}

$worker = new WorkerA;
$worker->registerJob('taskA', [$worker, 'taskA']);
$worker->run();
