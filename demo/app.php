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
$configPath = __DIR__ . '/config.php'; // Always use an absolute path to the config file!
$angela = new \Nekudo\Angela\Angela($configPath);


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
