<?php declare(strict_types=1);

namespace Kirameki\Process;

use Closure;
use Kirameki\Core\Exceptions\RuntimeException;
use Kirameki\Stream\FileStream;
use function array_keys;
use function array_map;
use function getcwd;
use function proc_get_status;
use function proc_open;
use const SIGTERM;

/**
 * @phpstan-consistent-constructor
 */
class Process
{
    /**
     * @param string|array<int, string> $command
     * @param string|null $directory
     * @param array<string, string>|null $envs
     * @param TimeoutInfo|null $timeout
     * @param int $termSignal
     * @param array<int, int> $exceptedExitCodes
     * @param FileStream|null $stdout
     * @param FileStream|null $stderr
     * @param array<int, Closure(ProcessResult): mixed> $completeCallbacks
     * @param Closure(ProcessResult): bool|null $onFailure
     */
    protected function __construct(
        protected string|array $command,
        protected ?string $directory = null,
        protected ?array $envs = null,
        protected ?TimeoutInfo $timeout = null,
        protected ?int $termSignal = null,
        protected ?array $exceptedExitCodes = null,
        protected ?FileStream $stdout = null,
        protected ?FileStream $stderr = null,
        protected readonly array $completeCallbacks = [],
        protected ?Closure $onFailure = null,
    ) {
    }

    /**
     * @param string|array<int, string> $command
     * @return static
     */
    public static function command(string|array $command): static
    {
        return new static($command);
    }

    /**
     * @param string|null $path
     * @return $this
     */
    public function inDirectory(?string $path): static
    {
        $this->directory = $path;
        return $this;
    }

    /**
     * @param array<string, string> $envs
     * @return $this
     */
    public function envs(?array $envs): static
    {
        $this->envs = $envs;
        return $this;
    }

    /**
     * @param float|null $durationSeconds
     * @param int $signal
     * @param float|null $killAfterSeconds
     * @return $this
     */
    public function timeout(
        ?float $durationSeconds,
        int $signal = SIGTERM,
        ?float $killAfterSeconds = 10.0,
    ): static {
        $this->timeout = ($durationSeconds !== null)
            ? new TimeoutInfo($durationSeconds, $signal, $killAfterSeconds)
            : null;
        return $this;
    }

    /**
     * @param int $signal
     * @return $this
     */
    public function termSignal(int $signal): static
    {
        $this->termSignal = $signal;
        return $this;
    }

    /**
     * @param array<int, int> $codes
     * @return $this
     */
    public function exceptedExitCodes(?array $codes): static
    {
        $this->exceptedExitCodes = $codes;
        return $this;
    }

    /**
     * @param Closure(int): bool $callback
     * @return $this
     */
    public function onFailure(Closure $callback): static
    {
        $this->onFailure = $callback;
        return $this;
    }

    /**
     * @return ProcessRunner
     */
    public function start(): ProcessRunner
    {
        $info = $this->buildInfo();

        $envs = $info->envs;
        $envVars = $envs !== null
            ? array_map(static fn($k, $v) => "{$k}={$v}", array_keys($envs), $envs)
            : null;

        // Observation of exit MUST be started before proc_open() is called.
        // @see ProcessObserver::observeSignal() for more info.
        $observer = ProcessObserver::observeSignal();

        $process = proc_open(
            $info->getFullCommand(),
            $this->getFileDescriptorSpec(),
            $pipes,
            $info->workingDirectory,
            $envVars,
        );

        if ($process === false) {
            throw new RuntimeException('Failed to start process.', [
                'info' => $info,
            ]);
        }

        $pid = proc_get_status($process)['pid'];

        return new ProcessRunner(
            $process,
            $observer,
            $info,
            $pid,
            $pipes,
            $this->completeCallbacks,
            $this->onFailure,
        );
    }

    /**
     * @return ProcessInfo
     */
    protected function buildInfo(): ProcessInfo
    {
        return new ProcessInfo(
            $this->command,
            $this->directory ?? (string) getcwd(),
            $this->envs,
            $this->timeout,
            $this->termSignal ?? SIGTERM,
            $this->exceptedExitCodes ?? [ExitCode::SUCCESS],
        );
    }

    /**
     * @return array<int, mixed>
     */
    protected function getFileDescriptorSpec(): array
    {
        return [
            ["pipe", "r"], // stdin
            ["pipe", "w"], // stdout
            ["pipe", "w"], // stderr
        ];
    }
}
