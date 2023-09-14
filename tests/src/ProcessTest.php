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

    public function test_command_timed_out_disable_timeout(): void
    {
        $result = (new ProcessBuilder(['bash', 'exit.sh']))
            ->timeout(0.01)->timeout(null)
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();

        $this->assertSame(['bash', 'exit.sh'], $result->info->executedCommand);
        $this->assertSame(ExitCode::SUCCESS, $result->exitCode);
        $this->assertNull($result->info->timeout);
        $this->assertFalse($result->timedOut());
    }

    public function test_command_timed_out_change_signal(): void
    {
        $result = (new ProcessBuilder(['bash', 'exit.sh', '--sleep', '1']))
            ->timeout(0.01, SIGINT, null)
            ->exceptedExitCodes(ExitCode::TIMED_OUT)
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();

        $this->assertSame(['timeout', '--signal', (string) SIGINT, '0.01s', 'bash', 'exit.sh', '--sleep', '1'], $result->info->executedCommand);
        $this->assertSame(ExitCode::TIMED_OUT, $result->exitCode);
        $this->assertSame(SIGINT, $result->info->timeout?->signal);
    }

    public function test_command_timeout_command_error(): void
    {
        $result = (new ProcessBuilder(['bash', 'exit.sh', '--sleep', '1']))
            ->timeout(-0.01)
            ->exceptedExitCodes(ExitCode::TIMEOUT_COMMAND_FAILED)
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();

        $this->assertSame(ExitCode::TIMEOUT_COMMAND_FAILED, $result->exitCode);
        $this->assertFalse($result->succeeded());
        $this->assertTrue($result->failed());
        $this->assertFalse($result->timedOut());
    }

    public function test_command_missing_script(): void
    {
        $this->expectExceptionMessage('Command not found. (code: 127, command: "noop.sh")');
        $this->expectException(ProcessFailedException::class);

        (new ProcessBuilder('noop.sh'))
            ->start()
            ->wait();
    }

    public function test_command_signal_on_running_process_as_success(): void
    {
        $process = (new ProcessBuilder(['bash', 'exit.sh', '--sleep', '1']))
            ->inDirectory($this->getScriptsDir())
            ->exceptedExitCodes(ExitCode::SIGHUP)
            ->start();

        $this->assertTrue($process->signal(SIGHUP));

        $process->wait();

        // try to signal again should return false.
        $this->assertFalse($process->signal(SIGHUP));
    }

    public function test_command_sigint_on_running_process(): void
    {
        $this->expectExceptionMessage('Terminated by SIGINT (2). (code: 130, command: ["bash","exit.sh","--sleep","1"])');
        $this->expectException(ProcessFailedException::class);

        $process = (new ProcessBuilder(['bash', 'exit.sh', '--sleep', '1']))
            ->inDirectory($this->getScriptsDir())
            ->start();

        $this->assertTrue($process->signal(SIGINT));

        $process->wait();
    }

    public function test_command_signal_on_segfault_process(): void
    {
        $this->expectExceptionMessage('Terminated by SIGSEGV (11). (code: 139, command: ["bash","exit.sh","--sleep","5"])');
        $this->expectException(ProcessFailedException::class);

        $process = (new ProcessBuilder(['bash', 'exit.sh', '--sleep', '5']))
            ->inDirectory($this->getScriptsDir())
            ->start();

        $this->assertTrue($process->signal(SIGSEGV));

        $process->wait();
    }

    public function test_command_signal_on_terminated_process(): void
    {
        $this->expectExceptionMessage('Terminated by SIGKILL (9). (code: 137, command: ["bash","exit.sh","--sleep","5"])');
        $this->expectException(ProcessFailedException::class);

        $process = (new ProcessBuilder(['bash', 'exit.sh', '--sleep', '5']))
            ->inDirectory($this->getScriptsDir())
            ->start();

        $this->assertTrue($process->signal(SIGKILL));

        $process->wait();
    }

    public function test_command_signal_on_terminated_process_with_timeout(): void
    {
        $process = (new ProcessBuilder(['bash', 'trap-sigterm.sh']))
            ->exceptedExitCodes(ExitCode::SIGKILL)
            ->inDirectory($this->getScriptsDir())
            ->start();

        // wait for the process to register trap.
        $output = $process->getIterator()->current();

        $signaled = $process->terminate(0.01);
        $result = $process->wait();

        $this->assertSame("trapped\n", $output);
        $this->assertTrue($signaled);
        $this->assertSame(ExitCode::SIGKILL, $result->exitCode);
    }

//
//    public function test_test(): void
//    {
//        $process1 = null;
//        foreach (range(0, 10) as $i) {
//            $process1 = (new ProcessBuilder(['sh', 'exit.sh', '--sleep', '1']))
//                ->inDirectory($this->getScriptsDir())
//                ->start();
//            usleep(1000);
//        }
//
//        sleep(3);
//
//        $process2 = (new ProcessBuilder(['sh', 'exit.sh', '--sleep', '1']))
//            ->inDirectory($this->getScriptsDir())
//            ->start();
//
//        $process2->wait();
//
//        $process1->wait();
//    }
}
