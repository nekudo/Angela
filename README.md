# Angela

A simple framework to manage Gearman workers in a PHP application.

## Features

* Start/Stop/Restart worker processes.
* Monitor/Keepalive worker processes.
* Manage worker groups.

## Installation

Using composer:

```composer require nekudo/angela```

## Documentation

Please see the "demo" folder for a complete example on how to use this framework.

Here's the short version:

**Step 1**

Create your workers and put them in a "worker" directory.
All workers have to extend the frameworks Worker class.

```php
<?php
use Nekudo\Angela\Worker;

class HelloAngela extends Worker
{
    protected function registerCallbacks()
    {
        $this->GearmanWorker->addFunction('sayHello', [$this, 'sayHello']);
    }

    public function sayHello(\GearmanJob $Job)
    {
        echo "Hello Angela!";
    }
}
```

**Step 2**

Now you can use Angela to manage your worker processes:

```php
<?php
$angela = new \Nekudo\Angela\Angela;
$angela->setGearmanCredentials('127.0.0.1', 4730);
$angela->setLogPath(__DIR__ . '/logs/');
$angela->setRunPath(__DIR__ . '/run/');
$angela->setWorkerPath(__DIR__ . '/worker/');

// Configure your workers
$angela->setWorkerConfig(
    [
        'hello' => [
            'classname' => 'Nekudo\Angela\Demo\HelloAngela',
            'filename' => 'HelloAngela.php',
            'instances' => 1,
        ],
    ]
);

// Start worker processes as defined in your config:
$angela->start();
```


## License

Released under the terms of the MIT license. See LICENSE file for details.