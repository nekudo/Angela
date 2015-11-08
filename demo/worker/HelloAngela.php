<?php namespace Nekudo\Angela\Demo;

/**
 * A dummy worker example.
 * Please see inline comments for additional iformation.
 */

/**
 * Require Angela base worker.
 * In a real application please use composer like e.g.:
 * require 'vendor/autoload.php';
 *
 */
require_once __DIR__ . '/../../src/Worker.php';

/**
 * Every worker needs to extend the Angela base worker.
 */
use Nekudo\Angela\Worker;

class HelloAngela extends Worker
{
    /**
     * This method is required by every worker. It is used to registers the actual worker
     * methods wich handle your jobs.
     */
    protected function registerCallbacks()
    {
        $this->GearmanWorker->addFunction('sayHello', [$this, 'sayHello']);
    }

    /**
     * A dummy methods.
     * Says hello...
     *
     * @param \GearmanJob $Job
     * @return bool
     */
    public function sayHello(\GearmanJob $Job)
    {
        /**
         * You should call this method to collect information about your workers.
         * This data can be shown using the Angela status method.
         */
        $this->countJob();

        // Get the arguments passed to your worker.
        $params = json_decode($Job->workload(), true);

        // Do some actual work here...
        echo "Hello Angela!";
        return true;
    }
}
