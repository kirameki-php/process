<?php declare(strict_types=1);

namespace Tests\Kirameki\Process;

use Kirameki\Process\ProcessBuilder;
use function usleep;
use const SIGCONT;
use const SIGSTOP;

final class ProcessObserverTest extends TestCase
{
    public function test_ignore_CLD_STOPPED(): void
    {
        $process = (new ProcessBuilder(['bash', 'exit.sh', '--sleep', '0.1']))
            ->inDirectory($this->getScriptsDir())
            ->start();

        $process->signal(SIGSTOP);
        usleep(10_000);
        $process->signal(SIGCONT);
        $result = $process->wait();

        $this->assertTrue($result->succeeded());
    }
}
