<?php declare(strict_types=1);

namespace Kirameki\Process\Events;

use Kirameki\Event\Event;
use Kirameki\Process\ProcessInfo;
use Kirameki\Process\ProcessResult;

class ProcessFinished extends Event
{
    /**
     * @param ProcessInfo $info
     * @param ProcessResult $result
     */
    public function __construct(
        public readonly ProcessInfo $info,
        public readonly ProcessResult $result,
    )
    {
    }
}
