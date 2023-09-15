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

    public function test_command_change_expected_exit_code(): void
    {
        $result = (new ProcessBuilder(['bash', 'exit.sh', (string) ExitCode::GENERAL_ERROR]))
            ->exceptedExitCodes(ExitCode::GENERAL_ERROR)
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();

        $this->assertSame(ExitCode::GENERAL_ERROR, $result->exitCode);
        $this->assertSame([ExitCode::GENERAL_ERROR], $result->info->exceptedExitCodes);
        $this->assertFalse($result->succeeded());
        $this->assertTrue($result->failed());
    }

    public function test_command_add_envs(): void
    {
        $envs = ['FOO' => 'BAR', 'BAZ' => 'QUX'];

        $result = (new ProcessBuilder(['bash', 'exit.sh']))
            ->envs($envs)
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();

        $this->assertSame($envs, $result->info->envs);
        $this->assertTrue($result->succeeded());
    }

    public function test_command_set_term_signal(): void
    {
        $process = (new ProcessBuilder(['bash', 'exit.sh', '--sleep', '2']))
            ->exceptedExitCodes(ExitCode::SIGINT)
            ->termSignal(SIGINT)
            ->inDirectory($this->getScriptsDir())
            ->start();

        $this->assertTrue($process->terminate());
        $result = $process->wait();
        $this->assertFalse($process->terminate());
        $this->assertSame(ExitCode::SIGINT, $result->exitCode);
        $this->assertSame(SIGINT, $result->info->termSignal);
    }

    public function test_exitCode_general_error_exception(): void
    {
        $this->expectExceptionMessage('General error. (code: 1, command: ["bash","exit.sh","1"])');
        $this->expectException(ProcessFailedException::class);

        (new ProcessBuilder(['bash', 'exit.sh', (string) ExitCode::GENERAL_ERROR]))
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();
    }

    public function test_exitCode_general_error_catch(): void
    {
        $process = (new ProcessBuilder(['bash', 'exit.sh', (string) ExitCode::GENERAL_ERROR]))
            ->inDirectory($this->getScriptsDir())
            ->exceptedExitCodes(ExitCode::GENERAL_ERROR)
            ->start()
            ->wait();

        $this->assertSame(ExitCode::GENERAL_ERROR, $process->exitCode);
        $this->assertFalse($process->succeeded());
        $this->assertTrue($process->failed());
    }

}
