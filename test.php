<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// store processes in an array as [$pid => $process, ...]
$processes = [];
$nprocesses = [];

// catch signals from child processes and unset processes from the array
pcntl_async_signals(true);
pcntl_signal(SIGCHLD, function($sig, $info) use (&$processes, &$nprocesses) {
    $pid = (int) $info['pid'];
    while($pid > 0) {
        if (array_key_exists($pid, $processes)) {
            unset($processes[$pid]);
        } else {
            echo "not found" . PHP_EOL;
        }
        $pid = pcntl_wait($status, WUNTRACED | WNOHANG);
        if ($pid > 0) {
            echo 'missed: ' . $pid . ':' . $info['status'] . PHP_EOL;
        }
    }
    usleep(10);
}, false);

// open 10 processes and store them in an array
foreach (range(0, 100) as $i) {
    $process = proc_open('NUM=`shuf -i 1-200 -n 1` && PROC=$$ && echo "p:$PROC n:$NUM" && exit $NUM', [], $pipes);
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
