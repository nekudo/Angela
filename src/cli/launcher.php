<?php
/**
 * This script is used to start a gearman worker.
 * It it called by Angelas "startupWorker" method.
 */
$longOpts = [
    'name:',
    'config:',
    'type:',
];
$options = getopt('', $longOpts);
if (empty($options['name'])) {
    echo "Error: Name parameter is missing.\n";
    exit(1);
}
if (empty($options['config'])) {
    echo "Error: Config parameter is missing.\n";
    exit(1);
}
if (empty($options['type'])) {
    echo "Error: Type parameter is missing.\n";
    exit(1);
}

$workerName = $options['name'];
$configPath = $options['config'];
$workerType = $options['type'];
if (!file_exists($configPath)) {
    echo "Error: Config file not found.\n";
    exit(1);
}

// load config:
$config = require $configPath;

// check worker type:
if (!isset($config['angela']['workerScripts'][$workerType])) {
    echo "Error: Unknown worker type.\n";
    exit(1);
}

// check worker file:
$pathToWorker = $config['angela']['workerPath'] . $config['angela']['workerScripts'][$workerType]['filename'];
if (!file_exists($pathToWorker)) {
    echo "Error: Worker file not found.\n";
    exit(1);
}


// startup worker:
require_once $pathToWorker;
$workerClassname = $config['angela']['workerScripts'][$workerType]['classname'];
$gearmanHost = $config['angela']['server']['host'];
$gearmanPort = $config['angela']['server']['port'];
$runPath = $config['angela']['pidPath'];
$worker = new $workerClassname($workerName, $gearmanHost, $gearmanPort, $runPath);
