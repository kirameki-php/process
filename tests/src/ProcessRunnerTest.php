<?php declare(strict_types=1);

namespace Tests\Kirameki\Process;

use Kirameki\Process\Exceptions\ProcessFailedException;
use Kirameki\Process\ExitCode;
use Kirameki\Process\ProcessBuilder;
use function dirname;
use function dump;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function sleep;
use const SIGCONT;
use const SIGHUP;
use const SIGINT;
use const SIGKILL;
use const SIGSEGV;
use const SIGSTOP;
use const SIGTERM;

final class ProcessRunnerTest extends TestCase
{
    public function test_isRunning(): void
    {
        $process = (new ProcessBuilder(['sh', 'exit.sh', '--sleep', '0.01']))
            ->exceptedExitCodes(ExitCode::SIGKILL)
            ->inDirectory($this->getScriptsDir())
            ->start();

        $this->assertTrue($process->isRunning());

        $process->signal(SIGKILL);
        $process->wait();

        $this->assertFalse($process->isRunning());
    }

    public function test_isDone(): void
    {
        $process = (new ProcessBuilder(['sh', 'exit.sh', '--sleep', '0.01']))
            ->exceptedExitCodes(ExitCode::SIGKILL)
            ->inDirectory($this->getScriptsDir())
            ->start();

        $this->assertFalse($process->isDone());

        $process->signal(SIGKILL);
        $process->wait();

        $this->assertTrue($process->isDone());
    }

    public function test_test(): void
    {
        $process = (new ProcessBuilder(['sh', 'exit.sh', '--sleep', '1']))
            ->exceptedExitCodes(ExitCode::SIGKILL)
            ->inDirectory($this->getScriptsDir())
            ->start();

        $process->signal(SIGSTOP);

        dump($process->isStopped());

        sleep(3);

        dump($process->isStopped());

        $this->assertTrue($process->isStopped());

        $process->signal(SIGCONT);

        $this->assertFalse($process->isStopped());

        $process->wait();

        $this->assertTrue($process->isDone());
    }
}
