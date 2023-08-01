<?php declare(strict_types=1);

namespace Kirameki\Process;

readonly class TimeoutInfo
{
    public function __construct(
        public float $durationSeconds,
        public int $signal,
        public ?float $killAfterSeconds,
    )
    {
    }
}
