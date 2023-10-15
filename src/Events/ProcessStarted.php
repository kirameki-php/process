<?php declare(strict_types=1);

namespace Kirameki\Process\Events;

use Kirameki\Event\Event;
use Kirameki\Process\ProcessInfo;

class ProcessStarted extends Event
{
    /**
     * @param ProcessInfo $info
     */
    public function __construct(
        public readonly ProcessInfo $info,
    )
    {
    }
}
