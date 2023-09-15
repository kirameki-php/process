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

    public function test_command_invalid_usage_error(): void
    {
        $this->expectExceptionMessage('Misuse of shell builtins. (code: 2, command: ["bash","./missing-keyword.sh"])');
        $this->expectException(ProcessFailedException::class);

        (new ProcessBuilder(['bash', './missing-keyword.sh']))
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();
    }

    public function test_command_invalid_usage_catch(): void
    {
        $process = (new ProcessBuilder(['bash', './missing-keyword.sh']))
            ->exceptedExitCodes(ExitCode::INVALID_USAGE)
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();

        $this->assertSame(ExitCode::INVALID_USAGE, $process->exitCode);
        $this->assertFalse($process->succeeded());
        $this->assertTrue($process->failed());
    }

    public function test_command_has_no_permission(): void
    {
        $this->expectExceptionMessage('Permission denied. (code: 126, command: "./non-executable.sh")');
        $this->expectException(ProcessFailedException::class);

        (new ProcessBuilder('./non-executable.sh'))
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();
    }

    public function test_command_timed_out_error(): void
    {
        $this->expectExceptionMessage('Timed out. (code: 124, command: ["bash","exit.sh","--sleep","1"])');
        $this->expectException(ProcessFailedException::class);

        (new ProcessBuilder(['bash', 'exit.sh', '--sleep', '1']))
            ->timeout(0.01)
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();
    }

    public function test_command_timed_out_catch(): void
    {
        $result = (new ProcessBuilder(['bash', 'exit.sh', '--sleep', '1']))
            ->timeout(0.01)
            ->exceptedExitCodes(ExitCode::TIMED_OUT)
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();

        $this->assertSame(['timeout', '--kill-after', '10s', '0.01s', 'bash', 'exit.sh', '--sleep', '1'], $result->info->executedCommand);
        $this->assertSame(ExitCode::TIMED_OUT, $result->exitCode);
        $this->assertSame(0.01, $result->info->timeout?->durationSeconds);
        $this->assertSame(SIGTERM, $result->info->timeout->signal);
        $this->assertSame(10.0, $result->info->timeout->killAfterSeconds);
        $this->assertFalse($result->succeeded());
        $this->assertTrue($result->failed());
        $this->assertTrue($result->timedOut());
    }
}
