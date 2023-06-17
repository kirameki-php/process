<?php declare(strict_types=1);

namespace Kirameki\Process;

use Closure;
use Kirameki\Core\Exceptions\RuntimeException;
use Kirameki\Stream\FileStream;
use Kirameki\Stream\StreamReadable;
use function array_keys;
use function array_map;
use function dump;
use function getcwd;
use function proc_open;
use function stream_set_blocking;
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
     * @param Closure(int): bool|null $onFailure
     * @param FileStream|null $stdin
     * @param FileStream|null $stdout
     * @param FileStream|null $stderr
     */
    protected function __construct(
        protected string|array $command,
        protected ?string $directory = null,
        protected ?array $envs = null,
        protected ?TimeoutInfo $timeout = null,
        protected ?int $termSignal = null,
        protected ?Closure $onFailure = null,
        protected ?FileStream $stdin = null,
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

        return new ProcessRunner(
            $process,
            $info,
            $pipes,
            $this->getStdios(),
            $this->onFailure,
        );
    }

    /**
     * @return ProcessInfo
     */
    public function buildInfo(): ProcessInfo
    {
        return new ProcessInfo(
            $this->command,
            $this->directory ?? (string) getcwd(),
            $this->envs,
            $this->timeout,
            $this->termSignal ?? SIGTERM,
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

    /**
     * @return array<int, FileStream>
     */
    protected function getStdios(): array
    {
        $maxMemory = 1024 * 1024; // 1MiB

        return [
            0 => $this->stdin ?? new FileStream("php://temp/maxmemory:{$maxMemory}"),
            1 => $this->stdout ?? new FileStream("php://temp/maxmemory:{$maxMemory}"),
            2 => $this->stderr ?? new FileStream("php://temp/maxmemory:{$maxMemory}"),
        ];
    }
}
