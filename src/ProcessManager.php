<?php declare(strict_types=1);

namespace Kirameki\Process;

use Kirameki\Event\EventDispatcher;

readonly class ProcessManager
{
    public function __construct(
        protected EventDispatcher $events,
    )
    {
    }

    /**
     * @param string|list<string> $command
     * @return ProcessBuilder
     */
    public function command(string|array $command): ProcessBuilder
    {
        return (new ProcessBuilder($command))
            ->onStarted($this->events->dispatch(...))
            ->onFinished($this->events->dispatch(...));
    }
}
