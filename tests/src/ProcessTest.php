<?php declare(strict_types=1);

namespace Tests\Kirameki\Process;

use Kirameki\Core\Testing\TestCase;
use Kirameki\Process\Shell;
use function dump;
use function usleep;

final class ProcessTest extends TestCase
{
    public function test_instantiate(): void
    {
        {
            $process = Shell::command(['sh', 'test.sh'])
                ->timeout(0.1)
                ->start();

            while ($process->isRunning()) {
                $out = $process->readStdout();
                if ($out !== '') {
                    dump($out);
                }
                usleep(10_000);
            }

            dump('done');

            usleep(1000);

            $out = $process->readStdout();
            dump($out);

            dump($process->wait());
        }
    }
}
