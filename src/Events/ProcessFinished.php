<?php declare(strict_types=1);

namespace Kirameki\Process\Events;

use Kirameki\Event\Event;
use Kirameki\Process\ProcessInfo;

class ProcessFinished extends Event
{
    /**
     * @param ProcessInfo $info
     * @param int $exitCode
     */
    public function __construct(
        public ProcessInfo $info,
        public int $exitCode,
    )
    {
    }
}
