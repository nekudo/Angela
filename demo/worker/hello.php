<?php

require_once __DIR__ . '/../../vendor/autoload.php';

class Hello extends \Nekudo\Angela\Worker\Worker
{

}


$worker = new Hello;
$worker->run();