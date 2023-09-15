<?php declare(strict_types=1);

namespace Tests\Kirameki\Process;

use Kirameki\Process\Exceptions\ProcessFailedException;
use Kirameki\Process\ExitCode;
use Kirameki\Process\ProcessBuilder;
use function dump;
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
            ->start();
        
        dump($result);
    }
}
