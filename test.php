<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// store processes in an array as [$pid => $process, ...]
$processes = [];

// catch signals from child processes and unset processes from the array
pcntl_async_signals(true);
pcntl_signal(SIGCHLD, function($sig, $info) use (&$processes) {
    unset($processes[$info['pid']]);
}, false);

// open 10 processes and store them in an array
foreach (range(0, 10) as $i) {
    $process = proc_open('echo $$', [], $pipes);
    $pid = proc_get_status($process)['pid'];
    $processes[$pid] = $process;
}

// wait for all processes to exit
while(true) {
    echo ' processes remaining:' . count($processes) . PHP_EOL;
    if (empty($processes)) {
        break;
    }
    sleep(1);
}

// Check that all processes have been removed.
var_dump($processes);
