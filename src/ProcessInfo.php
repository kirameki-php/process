<?php declare(strict_types=1);

namespace Kirameki\Process;

readonly class ProcessInfo
{
    /**
     * @param string|array<int, string> $definedCommand
     * @param string|array<int, string> $executedCommand
     * @param string $workingDirectory
     * @param array<string, string>|null $envs
     * @param TimeoutInfo|null $timeout
     * @param int $termSignal
     * @param array<int> $exceptedExitCodes
     * @param int $pid
     */
    public function __construct(
        public string|array $definedCommand,
        public string|array $executedCommand,
        public string $workingDirectory,
        public ?array $envs,
        public ?TimeoutInfo $timeout,
        public int $termSignal,
        public array $exceptedExitCodes,
        public int $pid,
    )
    {
    }
}
