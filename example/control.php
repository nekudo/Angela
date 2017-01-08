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
            print_r($response);
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
