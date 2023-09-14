<?php declare(strict_types=1);

namespace Tests\Kirameki\Process;

use Kirameki\Process\ExitCode;
use Kirameki\Process\ProcessBuilder;
use const SIGKILL;

final class ProcessRunnerTest extends TestCase
{
    public function test_isRunning(): void
    {
        $process = (new ProcessBuilder(['bash', 'exit.sh', '--sleep', '0.01']))
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
        $process = (new ProcessBuilder(['bash', 'exit.sh', '--sleep', '0.01']))
            ->exceptedExitCodes(ExitCode::SIGKILL)
            ->inDirectory($this->getScriptsDir())
            ->start();

        $this->assertFalse($process->isDone());

        $process->signal(SIGKILL);
        $process->wait();

        $this->assertTrue($process->isDone());
    }
}
