<?php

namespace Nekudo\Angela\Worker;

class GearmanWorker extends Worker
{
    /**
     * @var \GearmanWorker $broker
     */
    protected $broker;

    protected $callbacks = [];

    public function connectToBroker()
    {
        $this->broker = new \GearmanWorker;
        $this->broker->addServer(
            $this->config['broker']['credentials']['host'],
            $this->config['broker']['credentials']['port']
        );
    }

    public function registerCallback(string $taskName, callable $callback): bool
    {
        $this->callbacks[$taskName] = $callback;
        return true;
    }

    public function wait()
    {
        foreach ($this->callbacks as $taskName => $callback) {
            $this->broker->addFunction($taskName, $callback);
        }

        while ($this->broker->work()) {
            // wait for jobs...
        };
    }
}
