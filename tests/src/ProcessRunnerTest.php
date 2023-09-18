<?php declare(strict_types=1);

namespace Tests\Kirameki\Process;

use Kirameki\Process\ExitCode;
use Kirameki\Process\ProcessBuilder;
use Throwable;
use function array_keys;
use function dump;
use const SIGKILL;

final class ProcessRunnerTest extends TestCase
{
    public function test_getIterator(): void
    {
        $process = (new ProcessBuilder(['bash', 'outputs.sh']))
            ->inDirectory($this->getScriptsDir())
            ->start();

        $outs = [];
        foreach ($process as $type => $output) {
            $outs[$type][] = $output;
        }

        try {
            $this->assertSame([1, 2], array_keys($outs));
            $this->assertSame(["out\n"], $outs[1]);
            $this->assertSame([1, 2], array_keys($outs));
            $this->assertSame(["err\n"], $outs[2]);
        } catch (Throwable $e) {
            $process->wait();
            throw $e;
        }
    }

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

    public function test_writeToStdin(): void
    {
        $process = (new ProcessBuilder(['bash', 'exit.sh']))
            ->inDirectory($this->getScriptsDir())
            ->start();

        $process->writeToStdin("hello");
        $process->writeToStdin("world");

        $result = $process->wait();

        $this->assertSame("hello\nworld\n", $result->getStdin());
    }
}
