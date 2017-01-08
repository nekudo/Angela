<?php
namespace Nekudo\Angela\Example;

use Nekudo\Angela\Worker;

require_once __DIR__ . '/../../vendor/autoload.php';

class WorkerA extends Worker
{
    public function taskA(string $payload) : string
    {
        // Do some work:
        usleep((rand(2, 5) * 100000));

        // Return a response (needs to be string!):
        return $payload . '_completed_by_' . $this->workerId;
    }
}

// Create new worker:
$worker = new WorkerA;

// Register job this worker can do:
$worker->registerJob('taskA', [$worker, 'taskA']);

// Wait for jobs
$worker->run();
