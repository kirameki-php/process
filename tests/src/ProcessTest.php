<?php declare(strict_types=1);

namespace Tests\Kirameki\Process;

use Kirameki\Core\Testing\TestCase;
use Kirameki\Process\Exceptions\ProcessFailedException;
use Kirameki\Process\ExitCode;
use Kirameki\Process\ProcessBuilder;
use function dirname;
use const SIGHUP;
use const SIGINT;
use const SIGKILL;
use const SIGSEGV;
use const SIGTERM;

final class ProcessTest extends TestCase
{
    /**
     * @return string
     */
    protected function getScriptsDir(): string
    {
        return dirname(__DIR__) . '/scripts';
    }

    public function test_command_success(): void
    {
        $result = (new ProcessBuilder(['sh', 'exit.sh', '0']))
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();

        self::assertSame(0, $result->exitCode);
        self::assertNull($result->info->envs);
        self::assertSame(SIGTERM, $result->info->termSignal);
        self::assertSame('/app/tests/scripts', $result->info->workingDirectory);
        self::assertTrue($result->succeeded());
        self::assertFalse($result->failed());
    }

    public function test_exitCode_general_error_exception(): void
    {
        $this->expectExceptionMessage('General error. (code: 1, command: ["sh","exit.sh","1"])');
        $this->expectException(ProcessFailedException::class);

        (new ProcessBuilder(['sh', 'exit.sh', (string) ExitCode::GENERAL_ERROR]))
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();
    }

    public function test_exitCode_general_error_catch(): void
    {
        $process = (new ProcessBuilder(['sh', 'exit.sh', (string) ExitCode::GENERAL_ERROR]))
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
        $this->expectExceptionMessage('Misuse of shell builtins. (code: 2, command: "sh ./missing-keyword.sh")');
        $this->expectException(ProcessFailedException::class);

        (new ProcessBuilder(['sh', './missing-keyword.sh']))
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();
    }

    public function test_command_invalid_usage_catch(): void
    {
        $process = (new ProcessBuilder(['sh', './missing-keyword.sh']))
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
        $this->expectExceptionMessage('Timed out. (code: 124, command: ["sh","exit.sh","--sleep","1"])');
        $this->expectException(ProcessFailedException::class);

        (new ProcessBuilder(['sh', 'exit.sh', '--sleep', '1']))
            ->timeout(0.01)
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();
    }

    public function test_command_timed_out_catch(): void
    {
        $process = (new ProcessBuilder(['sh', 'exit.sh', '--sleep', '1']))
            ->timeout(0.01)
            ->exceptedExitCodes(ExitCode::TIMED_OUT)
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();

        $this->assertSame(ExitCode::TIMED_OUT, $process->exitCode);
        $this->assertFalse($process->succeeded());
        $this->assertTrue($process->failed());
        $this->assertTrue($process->timedOut());
    }

    public function test_command_timeout_command_error(): void
    {
        $process = (new ProcessBuilder(['sh', 'exit.sh', '--sleep', '1']))
            ->timeout(-0.01)
            ->exceptedExitCodes(ExitCode::TIMEOUT_COMMAND_FAILED)
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();

        $this->assertSame(ExitCode::TIMEOUT_COMMAND_FAILED, $process->exitCode);
        $this->assertFalse($process->succeeded());
        $this->assertTrue($process->failed());
        $this->assertFalse($process->timedOut());
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
        $process = (new ProcessBuilder(['sh', 'exit.sh', '--sleep', '1']))
            ->inDirectory($this->getScriptsDir())
            ->exceptedExitCodes(ExitCode::SIGHUP)
            ->start();

        self::assertTrue($process->signal(SIGHUP));

        $process->wait();

        // try to signal again should return false.
        self::assertFalse($process->signal(SIGHUP));
    }

    public function test_command_sigint_on_running_process(): void
    {
        $this->expectExceptionMessage('Terminated by SIGINT (2). (code: 130, command: ["sh","exit.sh","--sleep","1"])');
        $this->expectException(ProcessFailedException::class);

        $process = (new ProcessBuilder(['sh', 'exit.sh', '--sleep', '1']))
            ->inDirectory($this->getScriptsDir())
            ->start();

        self::assertTrue($process->signal(SIGINT));

        $process->wait();
    }

    public function test_command_signal_on_segfault_process(): void
    {
        $this->expectExceptionMessage('Terminated by SIGSEGV (11). (code: 139, command: ["sh","exit.sh","--sleep","5"])');
        $this->expectException(ProcessFailedException::class);

        $process = (new ProcessBuilder(['sh', 'exit.sh', '--sleep', '5']))
            ->inDirectory($this->getScriptsDir())
            ->start();

        self::assertTrue($process->signal(SIGSEGV));

        $process->wait();
    }

    public function test_command_signal_on_terminated_process(): void
    {
        $this->expectExceptionMessage('Terminated by SIGKILL (9). (code: 137, command: ["sh","exit.sh","--sleep","5"])');
        $this->expectException(ProcessFailedException::class);

        $process = (new ProcessBuilder(['sh', 'exit.sh', '--sleep', '5']))
            ->inDirectory($this->getScriptsDir())
            ->start();

        self::assertTrue($process->signal(SIGKILL));

        $process->wait();
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
