<?php declare(strict_types=1);

namespace Kirameki\Process;

use Closure;
use DateTimeInterface;
use Kirameki\Core\Exceptions\RuntimeException;
use Kirameki\Stream\FileStream;
use function array_keys;
use function array_map;
use function getcwd;
use function implode;
use function is_array;
use function microtime;
use function proc_open;
use function sprintf;
use const SIGTERM;

/**
 * @phpstan-consistent-constructor
 */
class Process
{
    public const DEFAULT_TERM_SIGNAL = SIGTERM;
    public const DEFAULT_TIMEOUT_SIGNAL = SIGTERM;
    public const DEFAULT_TIMEOUT_KILL_AFTER_SEC = 10;

    /**
     * @param string|array<int, string> $command
     * @param string|null $directory
     * @param array<string, string>|null $envs
     * @param float|DateTimeInterface|null $timeout Seconds or DateTimeInterface to set absolute timeout
     * @param int|null $timeoutSignal
     * @param float|null $timeoutKillAfterSeconds
     * @param int|null $termSignal
     * @param Closure(int): bool|null $exitCallback
     * @param FileStream|null $stdout
     * @param FileStream|null $stderr
     */
    protected function __construct(
        protected string|array $command,
        protected ?string $directory = null,
        protected ?array $envs = null,
        protected float|DateTimeInterface|null $timeout = null,
        protected ?int $timeoutSignal = null,
        protected ?float $timeoutKillAfterSeconds = null,
        protected ?int $termSignal = null,
        protected ?Closure $exitCallback = null,
        protected ?FileStream $stdout = null,
        protected ?FileStream $stderr = null,
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
     * @param non-empty-string $path
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
     * @param int|null $seconds
     * @param int $signal
     * @param int|float $killAfterSeconds
     * @return $this
     */
    public function timeoutIn(
        int|float|null $seconds,
        int $signal = self::DEFAULT_TIMEOUT_SIGNAL,
        int|float $killAfterSeconds = self::DEFAULT_TIMEOUT_KILL_AFTER_SEC,
    ): static {
        $this->timeout = $seconds;
        $this->timeoutSignal = $signal;
        $this->timeoutKillAfterSeconds = (float) $killAfterSeconds;
        return $this;
    }

    /**
     * @param DateTimeInterface $time
     * @param int $signal
     * @param int|float $killAfterSeconds
     * @return $this
     */
    public function timeoutAt(
        ?DateTimeInterface $time,
        int $signal = self::DEFAULT_TIMEOUT_SIGNAL,
        int|float $killAfterSeconds = self::DEFAULT_TIMEOUT_KILL_AFTER_SEC,
    ): static {
        $this->timeout = $time;
        $this->timeoutSignal = $signal;
        $this->timeoutKillAfterSeconds = (float) $killAfterSeconds;
        return $this;
    }

    /**
     * @param int $signal
     * @return $this
     */
    public function termSignal(?int $signal): static
    {
        $this->termSignal = $signal;
        return $this;
    }

    /**
     * @return $this
     */
    public function noOutput(): static
    {
        $stream = new FileStream('/dev/null', 'w+');
        return $this
            ->stdout($stream)
            ->stderr($stream);
    }

    /**
     * @param FileStream|null $stream
     * @return $this
     */
    public function stdout(?FileStream $stream): static
    {
        $this->stdout = $stream;
        return $this;
    }

    /**
     * @param FileStream|null $stream
     * @return $this
     */
    public function stderr(?FileStream $stream): static
    {
        $this->stderr = $stream;
        return $this;
    }

    /**
     * @param Closure(int): bool $callback
     * @return $this
     */
    public function onExit(Closure $callback): static
    {
        $this->exitCallback = $callback;
        return $this;
    }

    /**
     * @return ProcessHandler
     */
    public function start(): ProcessHandler
    {
        $command = $this->getCommand();
        $cwd = $this->getDirectory();
        $envs = $this->getEnvs();
        $envVars = $envs !== null
            ? array_map(static fn($k, $v) => "{$k}={$v}", array_keys($envs), $envs)
            : null;
        $termSignal = $this->getTermSignal();
        $fdSpec = [["pipe", "r"], ["pipe", "w"], ["pipe", "w"]];
        $memoryLimit = 1024 * 1024;
        $stdout = $this->stdout ?? new FileStream("php://temp/maxmemory:{$memoryLimit}");
        $stderr = $this->stderr ?? new FileStream("php://temp/maxmemory:{$memoryLimit}");

        $process = proc_open($command, $fdSpec, $pipes, $cwd, $envVars);
        if ($process === false) {
            throw new RuntimeException('Failed to start process.', [
                'command' => $command,
                'cwd' => $cwd,
                'envVars' => $envVars,
                'termSignal' => $termSignal,
                'exitCallback' => $this->exitCallback,
                'stdout' => $stdout,
                'stderr' => $stderr,
            ]);
        }

        return new ProcessHandler(
            $process,
            $command,
            $cwd,
            $envVars,
            $pipes,
            $termSignal,
            $this->exitCallback,
            $stdout,
            $stderr,
        );
    }

    /**
     * @return string|array<int, string>
     */
    public function getCommand(): string|array
    {
        $timeoutCommand = $this->buildTimeoutCommand();
        $command = $this->command;

        return is_array($command)
            ? array_merge($timeoutCommand, $command)
            : implode(' ', $timeoutCommand) . ' ' . $command;
    }

    /**
     * @return string
     */
    public function getDirectory(): string
    {
        return $this->directory ?? (string) getcwd();
    }

    /**
     * @return array<string, string>|null
     */
    public function getEnvs(): ?array
    {
        return $this->envs;
    }

    public function getTermSignal(): int
    {
        return $this->termSignal ?? self::DEFAULT_TERM_SIGNAL;
    }

    public function getTimeoutSignal(): int
    {
        return $this->timeoutSignal ?? self::DEFAULT_TIMEOUT_SIGNAL;
    }

    /**
     * @see https://man7.org/linux/man-pages/man1/timeout.1.html
     * @return array<int, string>
     */
    protected function buildTimeoutCommand(): array
    {
        if ($this->timeout === null) {
            return [];
        }

        $command = ['timeout'];

        if ($this->timeoutSignal !== self::DEFAULT_TIMEOUT_SIGNAL) {
            $command[] = '--signal';
            $command[] = (string) $this->getTimeoutSignal();
        }

        if ($this->timeoutKillAfterSeconds !== self::DEFAULT_TIMEOUT_KILL_AFTER_SEC) {
            $command[] = '--kill-after';
            $command[] = "{$this->timeoutKillAfterSeconds}s";
        }

        $timeoutSeconds = ($this->timeout instanceof DateTimeInterface)
            ? microtime(true) - (float) $this->timeout->format('U.u')
            : $this->timeout;

        $timeoutSeconds = (float) sprintf("%.3f", $timeoutSeconds);
        $command[] = "{$timeoutSeconds}s";

        return $command;
    }
}
