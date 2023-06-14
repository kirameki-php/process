<?php declare(strict_types=1);

namespace Tests\Kirameki\Process;

use Kirameki\Core\Signal;
use Kirameki\Core\Testing\TestCase;
use Kirameki\Process\Process;
use function dump;
use function sleep;
use function usleep;
use function var_dump;
use const SIGCHLD;
use const SIGCLD;

final class ProcessTest extends TestCase
{
    public function test_instantiate(): void
    {
        $process = null;

        Signal::handle(SIGCHLD, function() use (&$process) {
            dump('SIGCHLD');
        });

        $process = Process::command(['sh', 'test.sh'])
//            ->timeout(0.1)
            ->start();

        $process2 = Process::command(['sh', 'test.sh'])
//            ->timeout(0.1)
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

        usleep(1000);

        $out = $process->readStdout();
        dump($out);

        $result = $process->wait();
        dump($result);

        $process2->wait();

        sleep(5);
    }
}
