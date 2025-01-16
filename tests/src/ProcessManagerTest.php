<?php declare(strict_types=1);

namespace Tests\Kirameki\Process;

use Kirameki\Event\EventManager;
use Kirameki\Process\Events\ProcessFinished;
use Kirameki\Process\Events\ProcessStarted;
use Kirameki\Process\ProcessManager;

final class ProcessManagerTest extends TestCase
{
    public function test_command(): void
    {
        $events = new EventManager();
        $started = 0;
        $events->on(function (ProcessStarted $_) use (&$started) { $started++; });
        $finished = 0;
        $events->on(function (ProcessFinished $_) use (&$finished) { $finished++; });

        $procs = new ProcessManager($events);
        $processList = [];
        foreach(range(1, 3) as $i) {
            $processList[] = $procs->command('bash', 'exit.sh')
                ->inDirectory($this->getScriptsDir())
                ->start();
        }
        foreach ($processList as $process) {
            $process->wait();
        }

        $this->assertSame(3, $started);
        $this->assertSame(3, $finished);
    }
}
