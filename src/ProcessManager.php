<?php declare(strict_types=1);

namespace Kirameki\Process;

use Kirameki\Event\EventManager;
use function array_values;

readonly class ProcessManager
{
    public function __construct(
        protected EventManager $events,
    )
    {
    }

    /**
     * @param string ...$command
     * @return ProcessBuilder
     */
    public function command(string ...$command): ProcessBuilder
    {
        return (new ProcessBuilder(array_values($command)))
            ->onStarted($this->events->emit(...))
            ->onFinished($this->events->emit(...));
    }
}
