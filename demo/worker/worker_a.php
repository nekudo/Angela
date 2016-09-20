<?php

namespace Nekudo\Angela\Demo;

require_once __DIR__ . '/../../vendor/autoload.php';

class WorkerA extends \Nekudo\Angela\Worker\Worker
{
    /**
     * @param \Nekudo\Angela\Broker\Message $message
     */
    public function taskA($message)
    {
        $this->logger->debug('Executing task A...');
        $callbackId = $message->getCallbackId();
        $type = $message->getType();
        $this->broker->ack($message);

        sleep(5);

        if ($type === 'normal') {
            $this->broker->respond($callbackId, 'task A response');
        }
    }
}

$worker = new WorkerA;
$worker->registerTask('task_a', [$worker, 'taskA']);
$worker->run();
