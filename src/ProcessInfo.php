<?php declare(strict_types=1);

namespace Kirameki\Process;

use function array_merge;
use function implode;
use function is_array;
use function sprintf;
use const SIGTERM;

readonly class ProcessInfo
{
    /**
     * @param string|array<int, string> $command
     * @param string $workingDirectory
     * @param array<string, string>|null $envs
     * @param TimeoutInfo|null $timeout
     * @param int $termSignal
     * @param array<int> $exceptedExitCodes
     */
    public function __construct(
        public string|array $command,
        public string $workingDirectory,
        public ?array $envs,
        public ?TimeoutInfo $timeout,
        public int $termSignal,
        public array $exceptedExitCodes,
    ) {
    }

    /**
     * @return string|array<int, string>
     */
    public function getFullCommand(): string|array
    {
        $timeoutCommand = $this->buildTimeoutCommand();
        $command = $this->command;

        return is_array($command)
            ? array_merge($timeoutCommand, $command)
            : implode(' ', $timeoutCommand) . ' ' . $command;
    }

    /**
     * @see https://man7.org/linux/man-pages/man1/timeout.1.html
     * @return array<int, string>
     */
    protected function buildTimeoutCommand(): array
    {
        $timeout = $this->timeout;

        if ($timeout === null) {
            return [];
        }

        $command = ['timeout'];

        if ($timeout->signal !== SIGTERM) {
            $command[] = '--signal';
            $command[] = (string) $timeout->signal;
        }

        if ($timeout->killAfterSeconds !== null) {
            $command[] = '--kill-after';
            $command[] = "{$timeout->killAfterSeconds}s";
        }

        $timeoutSeconds = (float) sprintf("%.3f", $timeout->durationSeconds);
        $command[] = "{$timeoutSeconds}s";

        return $command;
    }
}
