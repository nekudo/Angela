<?php

namespace Nekudo\Angela\Demo;

use Nekudo\Angela\Worker;

require_once __DIR__ . '/../../vendor/autoload.php';

class WorkerC extends Worker
{
    public function foo(string $payload)
    {

    }
}

$worker = new WorkerC;
$worker->registerJob('foo', [$worker, 'foo']);
$worker->run();
