<?php

namespace Nekudo\Angela\Demo;

use Nekudo\Angela\Worker\GearmanWorker;

require_once __DIR__ . '/../../vendor/autoload.php';

class WorkerB extends GearmanWorker
{
    public function taskB($message)
    {

    }
}

$worker = new WorkerB;
$worker->registerCallback('task_b', [$worker, 'taskB']);
$worker->run();
