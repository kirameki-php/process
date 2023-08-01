<?php declare(strict_types=1);

namespace Kirameki\Process;

use Kirameki\Event\EventHandler;

readonly class ProcessManager
{
    public function __construct(
        protected EventHandler $eventHandler,
    )
    {
    }

    /**
     * @param string|list<string> $command
     * @return ProcessBuilder
     */
    public function command(string|array $command): ProcessBuilder
    {
        return new ProcessBuilder($this->eventHandler, $command);
    }
}
