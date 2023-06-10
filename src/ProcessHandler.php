<?php declare(strict_types=1);

namespace Kirameki\Process;

use Closure;
use Kirameki\Process\Exceptions\CommandFailedException;
use Kirameki\Process\Exceptions\CommandTimeoutException;
use Kirameki\Stream\FileStream;
use function array_keys;
use function is_resource;
use function proc_close;
use function proc_get_status;
use function proc_terminate;
use function stream_get_contents;
use function stream_set_blocking;
use function strlen;
use function usleep;
use const SEEK_CUR;
use const SIGKILL;

class ProcessHandler
{
    /**
     * @var array{
     *     command: string,
     *     pid: int,
     *     running: bool,
     *     signaled: bool,
     *     stopped: bool,
     *     exitcode: int,
     *     termsig: int,
     *     stopsig: int,
     *  }
     */
    protected array $status;

    /**
     * @var array{ 1: FileStream, 2: FileStream }
     */
    protected array $streams;

    /**
     * @var int|null
     */
    protected ?int $exitCode = null;

    /**
     * @param resource $process
     * @param string|array<int, string> $command
     * @param string $cwd
     * @param array<int, string>|null $envs
     * @param array<int, resource> $pipes
     * @param int $termSignal
     * @param Closure(int): bool|null $exitCallback
     * @param FileStream $stdout
     * @param FileStream $stderr
     */
    public function __construct(
        protected $process,
        protected readonly string|array $command,
        protected readonly string $cwd,
        protected readonly ?array $envs,
        protected readonly array $pipes,
        protected readonly int $termSignal,
        protected ?Closure $exitCallback,
        FileStream $stdout,
        FileStream $stderr,
    ) {
        $this->streams = [1 => $stdout, 2 => $stderr];
        $this->updateStatus();
    }

    /**
     * @return void
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * @param int $usleep
     * [Optional] Defaults to 10ms.
     * @return int
     */
    public function wait(int $usleep = 10_000): int
    {
        while ($this->isRunning()) {
            usleep($usleep);
        }

        $this->updateStatus();

        return $this->getExitCode();
    }

    /**
     * @param int $signal
     * @return bool
     */
    public function signal(int $signal): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }

        $result = proc_terminate($this->process, $signal);

        $this->updateStatus();

        return $result;
    }

    /**
     * @param float|null $timeoutSeconds
     * @return int
     */
    public function terminate(
        ?float $timeoutSeconds = null,
    ): int {
        if ($this->isDone()) {
            return $this->getExitCode();
        }

        $this->signal($this->termSignal);

        if ($timeoutSeconds !== null) {
            usleep((int) ($timeoutSeconds / 1e-6));
            if ($this->isRunning()) {
                $this->signal(SIGKILL);
            }
        }

        return $this->getExitCode();
    }

    /**
     * @return int
     */
    public function close(): int
    {
        $this->signal(SIGKILL);

        while ($this->isRunning()) {
            usleep(100);
        }

        return $this->getExitCode();
    }

    /**
     * @param bool $blocking
     * @return string|null
     */
    public function readStdout(bool $blocking = false): ?string
    {
        return $this->readPipe(1, $blocking);
    }

    /**
     * @param bool $blocking
     * @return string|null
     */
    public function readStderr(bool $blocking = false): ?string
    {
        return $this->readPipe(2, $blocking);
    }

    /**
     * @return string|array<int, string>
     */
    public function getCommand(): string|array
    {
        return $this->command;
    }

    /**
     * @return int
     */
    public function getPid(): int
    {
        return $this->status['pid'];
    }

    /**
     * @return int
     */
    public function getExitCode(): int
    {
        if (!$this->status['running']) {
            $this->updateStatus();
        }
        return $this->exitCode ?? -1;
    }

    /**
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->updateStatus()['running'];
    }

    /**
     * @return bool
     */
    public function isDone(): bool
    {
        return !$this->isRunning();
    }

    /**
     * @return bool
     */
    public function isStopped(): bool
    {
        return $this->updateStatus()['stopped'];
    }

    /**
     * @return bool
     */
    public function didTimeout(): bool
    {
        return $this->exitCode === 124;
    }

    /**
     * @return array{
     *     command: string,
     *     pid: int,
     *     running: bool,
     *     signaled: bool,
     *     stopped: bool,
     *     exitcode: int,
     *     termsig: int,
     *     stopsig: int,
     *  }
     */
    protected function updateStatus(): array
    {
        if (!is_resource($this->process)) {
            return $this->status;
        }

        $this->status = proc_get_status($this->process);

        if ($this->exitCode === null && $this->status['exitcode'] >= 0) {
            $this->exitCode = $this->status['exitcode'];
        }

        if (!$this->status['running']) {
            // Read remaining output from the pipes before calling
            // `proc_close(...)`. Otherwise unread data will be lost.
            // The output that has been read here is not read by the
            // user yet, so we seek back to the read position.
            foreach (array_keys($this->streams) as $fd) {
                $output = (string) $this->readPipe($fd);
                $this->streams[$fd]->seek(-strlen($output), SEEK_CUR);
            }

            proc_close($this->process);
        }

        if ($this->exitCode !== null) {
            $callback = $this->exitCallback ?? $this->handleExit(...);
            $callback($this->exitCode);
        }

        return $this->status;
    }

    /**
     * @param int $fd
     * @param bool $blocking
     * @return string|null
     */
    protected function readPipe(int $fd, bool $blocking = false): ?string {
        $stream = $this->streams[$fd];

        // If the pipes are closed (They close when the process closes)
        // check if there are any output to be read from `$stream`,
        // otherwise return **null**.
        if (!is_resource($this->pipes[$fd])) {
            // The only time `$stream` is not at EOF is when
            // 1. `updateStatus` was called
            // 2. the process (and pipes) was closed in `updateStatus`.
            // This is because `updateStatus` reads the remaining output from
            // the pipes before it gets closed (data is lost when closed)
            // and appends it to the stream ahead of time.
            return $stream->isNotEof()
                ? $stream->readToEnd()
                : null;
        }

        stream_set_blocking($this->pipes[$fd], $blocking);
        $output = (string) stream_get_contents($this->pipes[$fd]);
        $stream->write($output);
        return $output;
    }

    protected function handleExit(int $code): void
    {
        if ($code === 0) {
            return;
        }

        if ($code === 124) {
            throw new CommandTimeoutException($code);
        }

        throw new CommandFailedException($code);
    }
}
