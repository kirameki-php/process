<?php declare(strict_types=1);

namespace Tests\Kirameki\Process;

use Kirameki\Process\Exceptions\ProcessFailedException;
use Kirameki\Process\ExitCode;
use Kirameki\Process\ProcessBuilder;
use const SIGHUP;
use const SIGINT;
use const SIGKILL;
use const SIGSEGV;
use const SIGTERM;

final class ProcessTest extends TestCase
{
    public function test_command_success(): void
    {
        $result = (new ProcessBuilder(['bash', 'exit.sh', '0']))
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();

        $this->assertSame(0, $result->exitCode);
        $this->assertNull($result->info->envs);
        $this->assertSame(SIGTERM, $result->info->termSignal);
        $this->assertSame('/app/tests/scripts', $result->info->workingDirectory);
        $this->assertSame([], $result->info->exceptedExitCodes);
        $this->assertIsInt($result->info->pid);
        $this->assertTrue($result->succeeded());
        $this->assertFalse($result->failed());
    }
}
