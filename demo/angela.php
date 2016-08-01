<?php
require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/config.php';
$angela = new \Nekudo\Angela\Angela($config);
$angela->start();