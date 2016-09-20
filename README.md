# Angela

Angela is a PHP microservice worker framework based on RabbitMQ.

## Features

#### Worker process management

Angela starts/stops/restarts all your worker-processes.
You can define multiple worker-pools which will be started as child processes of the main Angela process. If a process
crashes a new one will be started.

#### Job handling

Each pool of workers is supposed to handle one kind of job or task. Using Angela you can pass jobs from your main
application to your worker-processes (or microservices). These jobs can return a response or just run in
the background.

## Installation

Using composer:

```composer require nekudo/angela```

## Documentation

Please see "demo" folder for some sample code. These are the most important files:

* __config.php:__ Holds all necessary configuration for Angela and your worker pools.
* __control.php:__ A simple control-script to start/stop/restart Angela. Use `php control.php start` to fire up Angela.
* __client.php:__ An example application sending jobs to be handled by worker-processes.
* __worker/*.php:__ All your worker-scripts handling the actual jobs.

## License

Released under the terms of the MIT license. See LICENSE file for details.