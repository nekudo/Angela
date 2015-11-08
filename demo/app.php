<?php
/**
 * A simple demo application to show possible usage of the Angela framework.
 *
 * This is a simple cli script allowing to start/stop/restart/monitor gearman workers.
 * Please see the inline comments for additional information.
 */
if (empty($argv)) {
    exit('Script can only be run in cli mode.' . PHP_EOL);
}
if (empty($argv[1])) {
    exit('No action given. Valid actions are: start|stop|restart|keepalive|status' . PHP_EOL);
}

/**
 * Require "Angela" the worker manager.
 * In a real application please use composers autoloading, like: require 'vendor/autoload.php';
 */
require_once __DIR__ . '/../src/Angela.php';

/**
 *  Create a new Angela instance.
 */
$angela = new \Nekudo\Angela\Angela;

/**
 * Set gearman credentials and path information.
 */
$angela->setGearmanCredentials('127.0.0.1', 4730);
// Log files for each worker-group go into this folder
$angela->setLogPath(__DIR__ . '/logs/');
// Folder containing worker pid files
$angela->setRunPath(__DIR__ . '/run/');
// Folder containig you actual workers
$angela->setWorkerPath(__DIR__ . '/worker/');

/**
 * Configure your workers.
 */
$angela->setWorkerConfig(
    [
        // 'hello' is the worker-type or group, add as many groups as you like...
        'hello' => [
            // The workers classname including namespace:
            'classname' => 'Nekudo\Angela\Demo\HelloAngela',
            // Workers filename. Worker has to be placed insite the "worker path" defined above:
            'filename' => 'HelloAngela.php',
            // Defines how many instances of this worker will be started:
            'instances' => 1,
        ],
    ]
);

/**
 * Now use Angela to manage your workers.
 */
$action = $argv[1];
switch ($action) {

    // Starts worker processes as defined in your worker configuration:
    case 'start':
        echo "Starting workers...\t";
        echo (($angela->start() === true) ? '[OK]' : '[FAILED]') . PHP_EOL;
        break;

    // Stops worker processes:
    case 'stop':
        echo "Stopping workers...\t";
        echo (($angela->stop() === true) ? '[OK]' : '[FAILED]') . PHP_EOL;
        break;

    // Restarts worker processes:
    case 'restart':
        echo "Restarting workers...\t";
        echo (($angela->restart() === true) ? '[OK]' : '[FAILED]') . PHP_EOL;
        break;

    // Restars processes if frozen or crashed so you always have your configured number of processes:
    case 'keepalive':
        $angela->keepalive();
        break;

    // Checks status of your workers:
    case 'status':
        $response = $angela->status();
        if (empty($response)) {
            echo "No workers running." . PHP_EOL;
            exit;
        }
        echo "\n### Currently active workers:\n\n";
        foreach ($response as $workerName => $workerData) {
            if ($workerData=== false) {
                $responseString = 'not responding';
            } else {
                $responseString = 'Ping: ' . round($workerData['ping'], 4)."s\t Jobs: " . $workerData['jobs_total'] .
                    ' ('. $workerData['avg_jobs_min'] . '/min) ';
            }
            echo $workerName . ":\t\t[" . $responseString . "]" . PHP_EOL;
        }
        break;
        
    default:
        exit('Invalid action. Valid actions are: start|stop|restart|keepalive' . PHP_EOL);
        break;
}
