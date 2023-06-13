<?php

require __DIR__.'/vendor/autoload.php';

use Kirameki\Process\Shell;

$class = new class {
    public function __construct()
    {
        $process = Shell::command(['sh', 'test.sh'])
            ->start();

        foreach ($process as $fd => $stdio) {
            dump($stdio);
        }

        while ($process->isRunning()) {
            $out = $process->readStdout();
            if ($out !== '') {
                dump($out);
            }
            usleep(10_000);
        }

        usleep(10000);

        $out = $process->readStdout();
        dump($out);

        usleep(10000);

        $out = $process->readStdout();
        dump($out);

        $result = $process->wait();
        dump($result);
    }
};
