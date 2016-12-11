<?php

namespace Nekudo\Angela\Worker;

class GearmanWorker extends Worker
{
    /**
     * @var \GearmanWorker $broker
     */
    protected $broker;

    /**
     * @var array $callbacks
     */
    protected $callbacks = [];

    /**
     * @inheritdoc
     */
    public function connectToBroker()
    {
        $this->broker = new \GearmanWorker;
        $this->broker->addServer(
            $this->config['broker']['credentials']['host'],
            $this->config['broker']['credentials']['port']
        );
    }

    /**
     * @inheritdoc
     */
    public function registerCallback(string $taskName, callable $callback): bool
    {
        $this->callbacks[$taskName] = $callback;
        return true;
    }

    /**
     * @inheritdoc
     */
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
