<?php declare(strict_types=1);

namespace Kirameki\Process;

use Closure;
use InvalidArgumentException;
use IteratorAggregate;
use Kirameki\Core\Exceptions\UnreachableException;
use Kirameki\Process\Exceptions\CommandFailedException;
use Kirameki\Stream\FileStream;
use Traversable;
use function in_array;
use function is_resource;
use function proc_close;
use function proc_terminate;
use function stream_get_contents;
use function stream_select;
use function stream_set_blocking;
use function strlen;
use function usleep;
use const SEEK_CUR;
use const SIGKILL;

/**
 * @implements IteratorAggregate<int, string>
 */
class ShellRunner implements IteratorAggregate
{
    /**
     * @param resource $process
     * @param ShellInfo $info
     * @param ShellStatus $status
     * @param array<int, resource> $pipes
     * @param FileStream $stdout
     * @param FileStream $stderr
     * @param Closure(int): bool|null $onFailure
     * @param ShellResult|null $result
     */
    public function __construct(
        protected $process,
        public readonly ShellInfo $info,
        protected readonly ShellStatus $status,
        protected readonly array $pipes,
        protected readonly FileStream $stdout,
        protected readonly FileStream $stderr,
        protected readonly ?Closure $onFailure,
        protected ?ShellResult $result = null,
    ) {
        $this->updateStatus();
    }

    /**
     * @return void
     */
    public function __destruct()
    {
        $this->signal(SIGKILL);
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        $read = [$this->pipes[1], $this->pipes[2]];
        $write = [];
        $except = [];
        while($this->isRunning()) {
            $count = stream_select($read, $write, $except, 60);
            if ($count > 0) {
                if (($stdout = (string) $this->readStdout()) !== '') {
                    yield 1 => $stdout;
                }
                if (($stderr = (string) $this->readStderr()) !== '') {
                    yield 2 => $stderr;
                }
            }
        }
    }

    /**
     * @param int $usleep
     * [Optional] Defaults to 10ms.
     * @return ShellResult
     */
    public function wait(int $usleep = 10_000): ShellResult
    {
        while ($this->isRunning()) {
            usleep($usleep);
        }

        return $this->getResult();
    }

    /**
     * @param int $signal
     * @return bool
     */
    public function signal(int $signal): bool
    {
        if ($this->isDone()) {
            return false;
        }

        $terminated = proc_terminate($this->process, $signal);

        $this->updateStatus();

        return $terminated;
    }

    /**
     * @param float|null $timeoutSeconds
     * @return ShellResult
     */
    public function terminate(?float $timeoutSeconds = null): ShellResult
    {
        if ($this->isDone()) {
            return $this->getResult();
        }

        $this->signal($this->info->termSignal);

        if ($timeoutSeconds !== null) {
            usleep((int) ($timeoutSeconds / 1e-6));
            if ($this->isRunning()) {
                $this->signal(SIGKILL);
            }
        }

        return $this->getResult();
    }

    /**
     * @return ShellResult
     */
    public function kill(): ShellResult
    {
        if ($this->isDone()) {
            return $this->getResult();
        }

        $this->signal(SIGKILL);

        while ($this->isRunning()) {
            usleep(100);
        }

        return $this->getResult();
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
     * @return int
     */
    public function getPid(): int
    {
        return $this->status->pid;
    }

    /**
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->updateStatus()->exitCode === null;
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
        return $this->updateStatus()->stopped;
    }

    /**
     * @return ShellStatus
     */
    protected function updateStatus(): ShellStatus
    {
        $status = $this->status;

        if (!is_resource($this->process)) {
            return $status;
        }

        $status->update();

        if ($status->exitCode !== null) {
            $this->drainPipes();
            proc_close($this->process);
            $this->handleExit($status->exitCode);
        }

        return $status;
    }

    /**
     * @param int $fd
     * @param bool $blocking
     * @return string|null
     */
    protected function readPipe(int $fd, bool $blocking = false): ?string {
        $stdio = match ($fd) {
            1 => $this->stdout,
            2 => $this->stderr,
            default => throw new InvalidArgumentException("Invalid file descriptor: $fd"),
        };

        // If the pipes are closed (They close when the process closes)
        // check if there are any output to be read from `$stdio`,
        // otherwise return **null**.
        if (!is_resource($this->pipes[$fd])) {
            // The only time `$stdio` is not at EOF is when
            // 1. `updateStatus` was called
            // 2. the process (and pipes) was closed in `updateStatus`.
            // This is because `updateStatus` reads the remaining output from
            // the pipes before it gets closed (data is lost when closed)
            // and appends it to the stdio ahead of time.
            return $stdio->isNotEof()
                ? $stdio->readToEnd()
                : null;
        }

        stream_set_blocking($this->pipes[$fd], $blocking);
        $output = (string) stream_get_contents($this->pipes[$fd]);
        $stdio->write($output);
        return $output;
    }

    protected function drainPipes(): void
    {
        // Read remaining output from the pipes before calling
        // `proc_close(...)`. Otherwise unread data will be lost.
        // The output that has been read here is not read by the
        // user yet, so we seek back to the read position.
        foreach ([1 => $this->stdout, 2 => $this->stderr] as $fd => $stdio) {
            $output = (string) $this->readPipe($fd);
            $stdio->seek(-strlen($output), SEEK_CUR);
        }
    }

    protected function handleExit(int $code): void
    {
        $this->result = $this->buildResult($code);

        if (in_array($code, $this->info->allowedExitCodes, true)) {
            return;
        }

        $callback = $this->onFailure ?? static fn() => true;

        if ($callback($code)) {
            throw new CommandFailedException($this->info->command, $code, [
                'shell' => $this,
            ]);
        }
    }

    /**
     * @param int $exitCode
     * @return ShellResult
     */
    protected function buildResult(int $exitCode): ShellResult
    {
        return new ShellResult(
            $this->info,
            $this->getPid(),
            $exitCode,
            $this->stdout,
            $this->stderr,
        );
    }

    /**
     * @return ShellResult
     */
    protected function getResult(): ShellResult
    {
        return $this->result ?? throw new UnreachableException('Shell result is not set');
    }
}
