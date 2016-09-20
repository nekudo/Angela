<?php

namespace Nekudo\Angela\Demo;

require_once __DIR__ . '/../../vendor/autoload.php';

class WorkerB extends \Nekudo\Angela\Worker\Worker
{
    /**
     * @param \Nekudo\Angela\Broker\Message $message
     */
    public function taskB($message)
    {
        $this->logger->debug('Executing task B...');
        $callbackId = $message->getCallbackId();
        $type = $message->getType();
        $this->broker->ack($message);

        if ($type === 'normal') {
            $this->broker->respond($callbackId, 'task B response');
        }
    }
}

$worker = new WorkerB;
$worker->registerTask('task_b', [$worker, 'taskB']);
$worker->run();
