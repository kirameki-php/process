<?php declare(strict_types=1);

namespace Tests\Kirameki\Process;

use Kirameki\Core\Testing\TestCase;
use Kirameki\Process\Exceptions\ProcessFailedException;
use Kirameki\Process\ProcessBuilder;
use function dirname;
use function dump;
use function range;
use function sleep;
use const SIGHUP;
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

    public function test_command(): void
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
//
//    public function test_exitCode_general_error(): void
//    {
//        $this->expectExceptionMessage('General error. (code: 1, command: ["sh","exit.sh","1"])');
//        $this->expectException(ProcessFailedException::class);
//
//        Process::command(['sh', 'exit.sh', '1'])
//            ->inDirectory($this->getScriptsDir())
//            ->start()
//            ->wait();
//    }
//
//    public function test_command_missing_sh_file(): void
//    {
//        $this->expectExceptionMessage('Misuse of shell builtins. (code: 2, command: ["sh","non-existent.sh"])');
//        $this->expectException(ProcessFailedException::class);
//
//        Process::command(['sh', 'non-existent.sh'])
//            ->start()
//            ->wait();
//    }
//
    public function test_command_has_no_permission(): void
    {
        $this->expectExceptionMessage('Permission denied. (code: 126, command: "./non-executable.sh")');
        $this->expectException(ProcessFailedException::class);

        ProcessBuilder::command('./non-executable.sh')
            ->inDirectory($this->getScriptsDir())
            ->start()
            ->wait();
    }

//    public function test_command_missing_script(): void
//    {
//        $this->expectExceptionMessage('Command not found. (code: 127, command: ["noop.sh"])');
//        $this->expectException(ProcessFailedException::class);
//
//        Process::command(['noop.sh'])
//            ->start()
//            ->wait();
//    }
//
//    public function test_command_signal_on_running_process(): void
//    {
//        $process = Process::command(['sh', 'exit.sh', '--sleep', '5'])
//            ->inDirectory($this->getScriptsDir())
//            ->start();
//
//        self::assertTrue($process->signal(SIGHUP));
//
//        $process->wait();
//
//        // try to signal again should return false.
//        self::assertFalse($process->signal(SIGHUP));
//    }
//
    public function test_command_signal_on_segfault_process(): void
    {
        $this->expectExceptionMessage('Terminated by SIGSEGV (11). (code: 139, command: ["sh","exit.sh","--sleep","5"])');
        $this->expectException(ProcessFailedException::class);

        $process = ProcessBuilder::command(['sh', 'exit.sh', '--sleep', '5'])
            ->inDirectory($this->getScriptsDir())
            ->start();

        self::assertTrue($process->signal(SIGSEGV));

        $process->wait();
    }

    public function test_command_signal_on_terminated_process(): void
    {
        $this->expectExceptionMessage('Terminated by SIGKILL (9). (code: 137, command: ["sh","exit.sh","--sleep","5"])');
        $this->expectException(ProcessFailedException::class);

        $process = ProcessBuilder::command(['sh', 'exit.sh', '--sleep', '5'])
            ->inDirectory($this->getScriptsDir())
            ->start();

        self::assertTrue($process->signal(SIGKILL));

        $process->wait();
    }

    public function test_test(): void
    {
        $process1 = null;
        foreach (range(0, 10) as $i) {
            $process1 = ProcessBuilder::command(['sh', 'exit.sh', '--sleep', '1'])
                ->inDirectory($this->getScriptsDir())
                ->start();
            usleep(1000);
        }

        sleep(3);

        $process2 = ProcessBuilder::command(['sh', 'exit.sh', '--sleep', '1'])
            ->inDirectory($this->getScriptsDir())
            ->start();

        $process2->wait();

        $process1->wait();

    }
}
