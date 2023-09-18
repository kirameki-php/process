<?php declare(strict_types=1);

namespace Tests\Kirameki\Process;

use Kirameki\Process\ExitCode;
use Kirameki\Process\ProcessBuilder;
use function usleep;

final class ProcessResultTest extends TestCase
{
    public function test_get_outputs(): void
    {
        $result = (new ProcessBuilder(['bash', 'outputs.sh']))
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();

        $this->assertSame(ExitCode::SUCCESS, $result->exitCode);
        $this->assertSame("out\n", $result->getStdout());
        $this->assertSame("err\n", $result->getStderr());
    }

    public function test_get_multi_outputs(): void
    {
        $process = (new ProcessBuilder(['bash', 'multi-outputs.sh']))
            ->inDirectory($this->getScriptsDir())
            ->start();

        usleep(100_000);

        $this->assertSame("out\n", $process->readStdoutBuffer());
        $this->assertSame("err\n", $process->readStderrBuffer());

        $process->terminate();

        $result = $process->wait();

        $this->assertSame("sigterm out\n", $result->readStdoutBuffer());
        $this->assertSame("sigterm err\n", $result->readStderrBuffer());
    }
}
