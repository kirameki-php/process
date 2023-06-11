<?php declare(strict_types=1);

namespace Tests\Kirameki\Process;

use Kirameki\Core\Testing\TestCase;
use Kirameki\Process\Shell;
use function dump;
use function proc_get_status;
use function proc_open;
use function sleep;
use function usleep;

final class ProcessTest extends TestCase
{
    public function test_instantiate(): void
    {
        {
            $process = Shell::command(['sh', 'test.sh'])
//                ->timeout(0.1)
                ->start();

            foreach ($process as $fd => $stdio) {
                dump($stdio);
            }

            while ($process->isRunning()) {
                $out = $process->readStdout();
                if ($out !== '') {
                    dump($out);
                }
                usleep(10_000);
            }

            usleep(1000);

            $out = $process->readStdout();
            dump($out);

            $result = $process->wait();
            dump($result);
        }
    }
}
