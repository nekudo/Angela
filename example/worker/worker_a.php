<?php

namespace Nekudo\Angela\Example;

use Nekudo\Angela\Worker;

require_once __DIR__ . '/../../vendor/autoload.php';

class WorkerA extends Worker
{
    public function taskA(string $payload) : string
    {
        //echo "worker " . $this->workerId . ' doing taskA with payload: ' . $payload . PHP_EOL;
        sleep(rand(1, 3));

        return $payload . '_completed_by_' . $this->workerId;
    }
}

$worker = new WorkerA;
$worker->registerJob('taskA', [$worker, 'taskA']);
$worker->run();
