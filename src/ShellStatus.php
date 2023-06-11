<?php declare(strict_types=1);

namespace Kirameki\Process;

use function is_resource;
use function proc_get_status;

class ShellStatus
{
    /**
     * @var int
     */
    public int $pid;

    /**
     * @var bool
     */
    public bool $running;

    /**
     * @var bool
     */
    public bool $stopped;

    /**
     * @var int|null
     */
    public ?int $lastSignal = null;

    /**
     * @var int|null
     */
    public ?int $exitCode = null;

    /**
     * @param resource $process
     */
    public function __construct(protected $process)
    {
        $this->update();
    }

    /**
     * @return void
     */
    public function update(): void
    {
        $process = $this->process;

        $status = proc_get_status($process);

        $this->pid = $status['pid'];
        $this->running = $status['running'];
        $this->stopped = $status['stopped'];

        if ($status['termsig'] !== 0) {
            $this->lastSignal = $status['termsig'];
        }

        if ($this->exitCode === null && $status['exitcode'] !== -1) {
            $this->exitCode = $status['exitcode'];
        }
    }
}
