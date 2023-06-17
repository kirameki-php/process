<?php

require __DIR__.'/vendor/autoload.php';

use Kirameki\Process\Process;
use Kirameki\Stream\FileStream;
use Kirameki\Stream\MemoryStream;

$class = new class {
    public function __construct()
    {
        $stream = new FileStream('php://temp');

        $process = Process::command(['sh', 'test.sh'])
            ->stdin($stream)
            ->start();

//        foreach ($process as $fd => $stdio) {
//            dump($stdio);
//        }

        while ($process->isRunning()) {
            $out = $process->readFromStdout();
            if ($out !== '') {
                dump($out);
            }
            if (str_contains((string) $out, 'Enter your name')) {
//                dump($process->writeToStdin('abc'));
            }
            usleep(10_000);
        }

        usleep(10000);

        $out = $process->readFromStdout();
        dump($out);

        usleep(10000);

        $out = $process->readFromStdout();
        dump($out);

        $result = $process->wait();
        dump($result);
    }
};
