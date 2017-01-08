<?php
if (empty($argv)) {
    exit('Script can only be run in cli mode.' . PHP_EOL);
}
if (empty($argv[1])) {
    exit('No action given. Valid actions are: start|stop|restart|status|flush-queue|kill' . PHP_EOL);
}

try {
    require_once __DIR__ . '/../vendor/autoload.php';
    $config = include __DIR__ . '/config.php';
    $angelaControl = new \Nekudo\Angela\AngelaControl($config);
    $action = $argv[1];
    switch ($action) {
        case 'start':
            $pid = $angelaControl->start();
            echo sprintf("Angela successfully started. (Pid: %d)", $pid) . PHP_EOL;
            break;
        case 'stop':
            $angelaControl->stop();
            echo "Angela successfully stoppped." .PHP_EOL;
            break;
        case 'kill':
            $result = $angelaControl->kill();
            echo 'All processes killed.' . PHP_EOL;
            break;
        case 'restart':
            $pid = $angelaControl->restart();
            echo sprintf("Angela successfully restarted. (Pid: %d)", $pid) . PHP_EOL;
            break;
        case 'status':
            $response = $angelaControl->status();
            echo '------------------------------ SERVER STATUS ------------------------------' . PHP_EOL;
            echo 'Angela Version: ' . $response['version'] . PHP_EOL;
            echo 'Start time:     ' . $response['starttime'] . PHP_EOL;
            echo 'Uptime:         ' . $response['uptime'] . PHP_EOL;
            echo '------------------------- WORKER/JOB INFORMATION --------------------------' . PHP_EOL;
            echo 'Job requests total:   ' . $response['job_info']['job_requests_total'] . PHP_EOL;
            echo 'Current queue length: ' . $response['job_info']['queue_length'] . PHP_EOL;
            echo PHP_EOL;
            echo 'Active worker per pool:' . PHP_EOL;
            foreach ($response['active_worker'] as $poolName => $workerCount) {
                echo ' + ' . $poolName . ': ' . $workerCount . ' ' . PHP_EOL;
            }
            echo PHP_EOL;
            echo 'Jobs completed per worker:' . PHP_EOL;
            foreach ($response['job_info']['worker_stats'] as $workerId => $jobsCompleted) {
                echo ' + ' . $workerId . ': ' . $jobsCompleted . PHP_EOL;
            }
            echo PHP_EOL;
            break;
        case 'flush-queue':
            $angelaControl->flushQueue();
            echo 'Queue flushed.' . PHP_EOL;
            break;
        default:
            exit('Invalid action. Valid actions are: start|stop|restart|status|flush-queue|kill' . PHP_EOL);
    }
} catch (\Nekudo\Angela\Exception\ControlException $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
