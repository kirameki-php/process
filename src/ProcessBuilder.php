<?php declare(strict_types=1);

namespace Kirameki\Process;

use Closure;
use Kirameki\Core\Exceptions\RuntimeException;
use Kirameki\Event\EventHandler;
use Kirameki\Event\Listeners\CallbackListener;
use Kirameki\Process\Events\ProcessFinished;
use Kirameki\Process\Events\ProcessStarted;
use Kirameki\Process\Exceptions\ProcessException;
use Kirameki\Stream\FileStream;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
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
     * @var EventHandler<ProcessStarted>|null
     */
    protected ?EventHandler $onStarted = null;

    /**
     * @var EventHandler<ProcessFinished>|null
     */
    protected ?EventHandler $onFinished = null;

    /**
     * @internal use Factory::command() to generate commands.
     *
     * @param string|array<int, string> $command
     * @param string|null $directory
     * @param array<string, string>|null $envs
     * @param TimeoutInfo|null $timeout
     * @param int $termSignal
     * @param array<int, int> $exceptedExitCodes
     * @param FileStream|null $stdout
     * @param FileStream|null $stderr
     */
    public function __construct(
        protected string|array $command,
        protected ?string $directory = null,
        protected ?array $envs = null,
        protected ?TimeoutInfo $timeout = null,
        protected ?int $termSignal = null,
        protected ?array $exceptedExitCodes = null,
        protected ?FileStream $stdout = null,
        protected ?FileStream $stderr = null,
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
        if ($durationSeconds !== null && $durationSeconds <= 0.0) {
            throw new ProcessException("Expected \$durationSeconds to be> 0.0. Got {$durationSeconds}.", [
                'command' => $this->command,
                'durationSeconds' => $durationSeconds,
            ]);
        }

        if ($killAfterSeconds !== null && $killAfterSeconds <= 0.0) {
            throw new ProcessException("Expected \$killAfterSeconds to be> 0.0. Got {$killAfterSeconds}.", [
                'command' => $this->command,
                'killAfterSeconds' => $killAfterSeconds,
            ]);
        }

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
     * @param int ...$codes
     * @return $this
     */
    public function exceptedExitCodes(int ...$codes): static
    {
        $this->exceptedExitCodes = array_values($codes);
        return $this;
    }

    /**
     * @param Closure(ProcessStarted): mixed $callback
     * @return $this
     */
    public function onStarted(Closure $callback): static
    {
        ($this->onStarted ??= new EventHandler(ProcessStarted::class))->append(new CallbackListener($callback));
        return $this;
    }

    /**
     * @param Closure(ProcessFinished): mixed $callback
     * @return $this
     */
    public function onFinished(Closure $callback): static
    {
        ($this->onFinished ??= new EventHandler(ProcessFinished::class))->append(new CallbackListener($callback));
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
            // @codeCoverageIgnoreStart
            throw new RuntimeException('Failed to start process.', [
                'info' => $this->buildInfo($shellCommand, -1),
            ]);
            // @codeCoverageIgnoreEnd
        }

        $pid = proc_get_status($process)['pid'];

        $info = $this->buildInfo($shellCommand, $pid);

        $this->onStarted?->emit(new ProcessStarted($info));

        return new ProcessRunner(
            $process,
            $observer,
            $info,
            $pipes,
            $this->onFinished,
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
            $this->exceptedExitCodes ?? [],
            $pid,
        );
    }

    /**
     * @return string|list<string>
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
     * @return list<string>
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
