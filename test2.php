<?php

pcntl_async_signals(true);
pcntl_signal(SIGCHLD, function($sig, $info) {
    usleep(100);
    pcntl_wait($status, WUNTRACED | WNOHANG);
    echo pcntl_wexitstatus($status);
    while(($pid = pcntl_wait($status, WUNTRACED | WNOHANG)) > 0) {
        echo 'SIGCHLD WUNTRACED: ' . $pid . ' exit code: ' . pcntl_wexitstatus($status) . PHP_EOL;
    }
}, false);

foreach (range(0, 100) as $i) {
    // exit proc with exit code: $i
    proc_open('echo "$$ exiting with code: '.$i.'" && exit ' . $i, [], $pipes);
}
