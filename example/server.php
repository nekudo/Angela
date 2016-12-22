<?php
require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/config.php';
$server = new \Nekudo\Angela\Server($config);
$server->start();
