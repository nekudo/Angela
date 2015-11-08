<?php
/**
 * This script is used to start a gearman worker.
 * It it called by Angelas "startupWorker" method.
 */
$longOpts = [
    'name:',
    'path:',
    'classname:',
    'gh:',
    'gp:',
    'rp:',
];
$options = getopt('', $longOpts);
if (empty($options['name'])) {
    echo "Name is required.";
    exit(1);
}
if (empty($options['path'])) {
    echo "Path is required.";
    exit(1);
}
if (empty($options['classname'])) {
    echo "Classname is required.";
    exit(1);
}
if (empty($options['gh'])) {
    echo "Gearman hostname is required.";
    exit(1);
}
if (empty($options['gp'])) {
    echo "Gearman port is required.";
    exit(1);
}
if (empty($options['rp'])) {
    echo "Run path is required.";
    exit(1);
}

$workerName = $options['name'];
$workerPath = $options['path'];
$workerClassname = base64_decode($options['classname']);
$gearmanHost = $options['gh'];
$gearmanPort = (int)$options['gp'];
$runPath = $options['rp'];

if (!file_exists($workerPath)) {
    echo "Worker not found.";
    exit(1);
}

// startup worker:
require_once __DIR__ . '/../Worker.php';
require_once $workerPath;
$worker = new $workerClassname($workerName, $gearmanHost, $gearmanPort, $runPath);
