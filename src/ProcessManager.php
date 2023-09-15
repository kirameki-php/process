<?php declare(strict_types=1);

namespace Kirameki\Process;

use Kirameki\Event\EventDispatcher;
use function array_values;

readonly class ProcessManager
{
    public function __construct(
        protected EventDispatcher $events,
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
            ->onStarted($this->events->dispatch(...))
            ->onFinished($this->events->dispatch(...));
    }
}
