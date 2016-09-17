<?php
if (empty($argv)) {
    exit('Script can only be run in cli mode.' . PHP_EOL);
}
if (empty($argv[1])) {
    exit('No action given. Valid actions are: start|stop|restart|status' . PHP_EOL);
}

try {
    require_once __DIR__ . '/../vendor/autoload.php';
    $config = include __DIR__ . '/config.php';
    $angelaControl = new \Nekudo\Angela\AngelaControl($config);
    $action = $argv[1];
    switch ($action) {
        case 'start':
            $angelaControl->start();
            break;
        case 'stop':
            $response = $angelaControl->stop();
            echo $response . PHP_EOL;
            break;
        case 'status':
            $response = $angelaControl->status();
            if (empty($response)) {
                echo 'Unknown Status' . PHP_EOL;
            } else {
                echo 'Worker Status:' . PHP_EOL;
                foreach ($response as $poolName => $wokerCount) {
                    echo sprintf("Pool %s: %d active workers.", $poolName, $wokerCount) . PHP_EOL;
                }
            }
            break;
        default:
            exit('Invalid action. Valid actions are: start|stop|restart|status' . PHP_EOL);
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
