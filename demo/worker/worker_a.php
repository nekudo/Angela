<?php

namespace Nekudo\Angela\Demo;

use Nekudo\Angela\Worker\GearmanWorker;

require_once __DIR__ . '/../../vendor/autoload.php';

class WorkerA extends GearmanWorker
{
    public function taskA($message)
    {

    }
}

$worker = new WorkerA;
$worker->registerCallback('task_a', [$worker, 'taskA']);
$worker->run();
