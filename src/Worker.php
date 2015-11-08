<?php namespace Nekudo\Angela;

abstract class Worker
{
    /** @var string Unique name to identify worker. */
    protected $workerName;

    /** @var \GearmanWorker */
    protected $GearmanWorker;

    /** @var int Jobs handled by worker since start. */
    protected $jobsTotal = 0;

    /** @var int Worker startup time. */
    protected $startupTime = 0;

    protected $runPath;

    abstract protected function registerCallbacks();

    public function __construct($workerName, $gearmanHost, $gearmanPort, $runPath)
    {
        $this->workerName = $workerName;
        $this->runPath = $runPath;
        $this->startupTime = time();

        $this->GearmanWorker = new \GearmanWorker;
        $this->GearmanWorker->addServer($gearmanHost, $gearmanPort);

        // Register methods every worker has:
        $this->GearmanWorker->addFunction('ping_' . $this->workerName, array($this, 'ping'));
        $this->GearmanWorker->addFunction('jobinfo_' . $this->workerName, array($this, 'getJobInfo'));
        $this->GearmanWorker->addFunction('pidupdate_' . $this->workerName, array($this, 'updatePidFile'));

        $this->registerCallbacks();

        // Let's roll...
        $this->startup();
    }

    /**
     * Startup worker and wait for jobs.
     */
    protected function startup()
    {
        $this->updatePidFile();
        while ($this->GearmanWorker->work());
    }

    /**
     * Simple ping method to test if worker is alive.
     *
     * @param \GearmanJob $Job
     */
    public function ping($Job)
    {
        $Job->sendData('pong');
    }

    /**
     * Increases job counter.
     */
    public function countJob()
    {
        $this->jobsTotal++;
    }

    /**
     * Returns information about jobs handled.
     *
     * @param \GearmanJob $Job
     */
    public function getJobInfo($Job)
    {
        $uptimeSeconds = time() - $this->startupTime;
        $uptimeSeconds = ($uptimeSeconds === 0) ? 1 : $uptimeSeconds;
        $avgJobsMin = $this->jobsTotal / ($uptimeSeconds / 60);
        $avgJobsMin = round($avgJobsMin, 2);
        $response = [
            'jobs_total' => $this->jobsTotal,
            'avg_jobs_min' => $avgJobsMin,
            'uptime_seconds' => $uptimeSeconds,
        ];
        $Job->sendData(json_encode($response));
    }

    /**
     * Updates PID file for the worker.
     *
     * @return bool
     * @throws WebsocketException
     */
    public function updatePidFile()
    {
        $pidPath = $this->runPath . $this->workerName . '.pid';
        if (file_put_contents($pidPath, time()) === false) {
            throw new WebsocketException('Could not create PID file.');
        }
        return true;
    }
}
