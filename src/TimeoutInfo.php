<?php declare(strict_types=1);

namespace Kirameki\Process;

use const SIGTERM;

readonly class TimeoutInfo
{
    public const DEFAULT_SIGNAL = SIGTERM;
    public const DEFAULT_KILL_AFTER_SEC = 10.0;

    public function __construct(
        public float $durationSeconds,
        public int $signal = self::DEFAULT_SIGNAL,
        public ?float $killAfterSeconds = self::DEFAULT_KILL_AFTER_SEC,
    ) {
    }
}
