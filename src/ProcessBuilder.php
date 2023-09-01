<?php declare(strict_types=1);

namespace Kirameki\Process;

use Closure;
use Kirameki\Core\Exceptions\RuntimeException;
use Kirameki\Event\EventHandler;
use Kirameki\Process\Events\ProcessStarted;
use Kirameki\Stream\FileStream;
use function array_keys;
use function array_map;
use function array_merge;
use function getcwd;
use function implode;
use function is_array;
use function proc_get_status;
use function proc_open;
use function sprintf;
use const SIGTERM;

class ProcessBuilder
{
    /**
     * @internal use Factory::command() to generate commands.
     *
     * @param EventHandler $eventHandler
     * @param string|array<int, string> $command
     * @param string|null $directory
     * @param array<string, string>|null $envs
     * @param TimeoutInfo|null $timeout
     * @param int $termSignal
     * @param array<int, int> $exceptedExitCodes
     * @param FileStream|null $stdout
     * @param FileStream|null $stderr
     * @param array<int, Closure(ProcessResult): mixed> $onCompletedCallbacks
     */
    public function __construct(
        protected EventHandler $eventHandler,
        protected string|array $command,
        protected ?string $directory = null,
        protected ?array $envs = null,
        protected ?TimeoutInfo $timeout = null,
        protected ?int $termSignal = null,
        protected ?array $exceptedExitCodes = null,
        protected ?FileStream $stdout = null,
        protected ?FileStream $stderr = null,
        protected array $onCompletedCallbacks = [],
    )
    {
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
     * @param Closure(ProcessResult): bool $callback
     * @return $this
     */
    public function onCompleted(Closure $callback): static
    {
        $this->onCompletedCallbacks[] = $callback;
        return $this;
    }

    /**
     * @return ProcessRunner
     */
    public function start(): ProcessRunner
    {
        $shellCommand = $this->buildShellCommand();

        $envs = $this->envs;
        $envVars = $envs !== null
            ? array_map(static fn($k, $v) => "{$k}={$v}", array_keys($envs), $envs)
            : null;

        // Observation of exit MUST be started before proc_open() is called.
        // @see ProcessObserver::observeSignal() for more info.
        $observer = ProcessObserver::observe();

        $process = proc_open(
            $shellCommand,
            $this->getFileDescriptorSpec(),
            $pipes,
            $this->directory,
            $envVars,
        );

        if ($process === false) {
            throw new RuntimeException('Failed to start process.', [
                'info' => $this->buildInfo($shellCommand, -1),
            ]);
        }

        $pid = proc_get_status($process)['pid'];

        $info = $this->buildInfo($shellCommand, $pid);

        $this->eventHandler->dispatch(new ProcessStarted($info));

        return new ProcessRunner(
            $process,
            $observer,
            $info,
            $pipes,
            $this->onCompletedCallbacks,
        );
    }

    /**
     * @param string|list<string> $executedCommand
     * @param int $pid
     * @return ProcessInfo
     */
    protected function buildInfo(string|array $executedCommand, int $pid): ProcessInfo
    {
        return new ProcessInfo(
            $this->command,
            $executedCommand,
            $this->directory ?? (string) getcwd(),
            $this->envs,
            $this->timeout,
            $this->termSignal ?? SIGTERM,
            $this->exceptedExitCodes ?? [ExitCode::SUCCESS],
            $pid,
        );
    }

    /**
     * @return string|array<int, string>
     */
    public function buildShellCommand(): string|array
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
