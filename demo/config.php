<?php
return [
    'angela' => [

        'workerPath' => __DIR__ . '/Worker/',
        'pidPath' => __DIR__ . '/../../cli/run/',
        'logPath' => __DIR__ . '/../../logs/',

        'server' => [
            'type' => 'gearman', // valid servers are: gearman, rabbit
            'host' => '127.0.0.1',
            'port' => 4730,
            'user' => '',
            'pass' => '',
        ],

        'timeTillGhost' => 1200,

        // Worker startup configuration:
        'workerScripts' => [
            // 'hello' is the worker-type or group, add as many groups as you like...
            'hello' => [
                // The workers classname including namespace:
                'classname' => 'Nekudo\Angela\Demo\HelloAngela',
                // Workers filename. Worker has to be placed insite the "worker path" defined above:
                'filename' => 'HelloAngela.php',
                // Defines how many instances of this worker will be started:
                'instances' => 1,
            ],
        ]
    ],
];