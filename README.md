# What is Angela?

Angela is a PHP worker/microservice framework based on ZeroMQ.

A typical Angela application consists of a job-server, a client to communicate with the server and workers which do the actual jobs. Angela provides the job server, the client and an API so you can easily implement your worker processes.

```
             +--------+
             | Client |
             +--------+
                 ^   
                 |   
                 v   
           +------------+
           | Job Server |
           +------------+
     +-------^   ^    ^------+
     |           |           |
     |           |           |
     v           v           v
+--------+   +--------+   +--------+
| Worker |   | Worker |   | Worker |
+--------+   +--------+   +--------+

```

## Features

### Job server

The job server is Angelas main process. It manages all your workers, listens for new job-requests, distributes these jobs to your workers and send back responses to the client.
One server can manage multiple pools of workers and hence handle various types of jobs.

The job server will fire up worker-processes as defined in your project configuration. It will monitor the wokers and for example restart processes if a worker crashes.

It is also capable of basic load-balancing so jobs will always be passed to the next idle worker.

### Worker

Angela provides an API to easily build worker processes. Each worker typically does one kind of job (even though in can handle multiple types).
You would than start multiple pools of worker processes which than handle the different kind of jobs required in your application.

__Example__
```php
<?php

class WorkerA extends \Nekudo\Angela\Worker
{
    public function taskA(string $payload) : string
    {
        // Do some work:
        sleep(1);

        // Return a response (needs to be string!):
        return strrev($payload);
    }
}

// Create new worker and register jobs:
$worker = new WorkerA;
$worker->registerJob('taskA', [$worker, 'taskA']);
$worker->run();
```

### Client

The client is a simple class which allows you to send commands or job-requests to the server. It can send commands, normal jobs or background-jobs.

Normal jobs are blocking as the client will wait for a response. Background jobs however are non-blocking. The will be processes by the server but the client does not wait for a response.

__Example__
```php
<?php
$client = new \Nekudo\Angela\Client;
$client->addServer('tcp://127.0.0.1:5551');
$result = $client->doNormal('taskA', 'some payload'); // result is "daolyap emos"
$client->close();
```



## Requirements

* PHP >= 7.0
* [ZMQ PHP extension](http://php.net/manual/en/zmq.requirements.php)
* [ev PHP extension](http://php.net/manual/en/ev.installation.php) (optional but recommand for better performance)

## Installation

Using composer:

```composer require nekudo/angela```

## Documentation

Please see "example" folder for some sample code. These are the most important files:

* __config.php:__ Holds all necessary configuration for the server and worker pools.
* __control.php:__ A simple control-script to start/stop/restart your server and worker processes. Use `php control.php start` to fire up Angela.
* __client.php:__ An example client sending jobs to the job server.
* __worker/*.php:__ All your worker-scripts handling the actual jobs.

## License

Released under the terms of the MIT license. See LICENSE file for details.