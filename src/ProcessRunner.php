<?php declare(strict_types=1);

namespace Kirameki\Process;

use Closure;
use IteratorAggregate;
use Kirameki\Core\Exceptions\UnreachableException;
use Kirameki\Core\Signal;
use Kirameki\Core\SignalEvent;
use Kirameki\Process\Exceptions\ProcessFailedException;
use Kirameki\Stream\FileStream;
use Traversable;
use function fwrite;
use function in_array;
use function is_int;
use function is_resource;
use function proc_close;
use function proc_get_status;
use function proc_terminate;
use function stream_get_contents;
use function stream_select;
use function stream_set_blocking;
use function strlen;
use function usleep;
use const PHP_EOL;
use const SEEK_CUR;
use const SIGKILL;

/**
 * @implements IteratorAggregate<int, string>
 */
class ProcessRunner implements IteratorAggregate
{
    /**
     * @var int
     */
    public readonly int $pid;

    /**
     * @param resource $process
     * @param ProcessInfo $info
     * @param array<int, resource> $pipes
     * @param array<FileStream> $stdios
     * @param Closure(int, ProcessResult): bool|null $onFailure
     * @param ProcessResult|null $result
     */
    public function __construct(
        protected $process,
        public readonly ProcessInfo $info,
        protected readonly array $pipes,
        protected readonly array $stdios,
        protected readonly ?Closure $onFailure,
        protected ?ProcessResult $result = null,
    ) {
        $this->pid = proc_get_status($this->process)['pid'];

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                stream_set_blocking($pipe, false);
            }
        }

        Signal::handle(SIGCHLD, function(SignalEvent $event) {
            if ($event->info['pid'] === $this->pid) {
                $this->handleSigChld($event->info['status']);
                $event->evictCallback();
            }
        });
    }

    /**
     * @return void
     */
    public function __destruct()
    {
        $this->signal(SIGKILL);
    }

    /**
     * @return bool
     */
    public function isRunning(): bool
    {
        return is_resource($this->process);
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
        return proc_get_status($this->process)['stopped'];
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
            $count = stream_select($read, $write, $except, null);
            if ($count > 0) {
                if (($stdout = (string) $this->readFromStdout()) !== '') {
                    yield 1 => $stdout;
                }
                if (($stderr = (string) $this->readFromStderr()) !== '') {
                    yield 2 => $stderr;
                }
            }
        }
    }

    /**
     * @param int $usleep
     * [Optional] Defaults to 10ms.
     * @return ProcessResult
     */
    public function wait(int $usleep = 10_000): ProcessResult
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
        if ($this->isRunning()) {
            proc_terminate($this->process, $signal);
            return true;
        }
        return false;
    }

    /**
     * @param float|null $timeoutSeconds
     * @return bool
     */
    public function terminate(?float $timeoutSeconds = null): bool
    {
        $signaled = $this->signal($this->info->termSignal);

        if ($signaled && $timeoutSeconds !== null) {
            usleep((int) ($timeoutSeconds / 1e-6));

            if ($this->isRunning()) {
                $this->signal(SIGKILL);
            }
        }

        return $signaled;
    }

    /**
     * @param string $input
     * @param bool $appendEol
     * @return bool
     */
    public function writeToStdin(string $input, bool $appendEol = true): bool
    {
        if ($appendEol) {
            $input .= PHP_EOL;
        }

        $this->stdios[0]->write($input);

        $length = fwrite($this->pipes[0], $input);

        return is_int($length);
    }

    /**
     * @return string|null
     */
    public function readFromStdout(): ?string
    {
        return $this->readPipe($this->pipes[1], $this->stdios[1]);
    }

    /**
     * @return string|null
     */
    public function readFromStderr(): ?string
    {
        return $this->readPipe($this->pipes[2], $this->stdios[2]);
    }

    /**
     * @param int $exitCode
     * @return void
     */
    protected function handleSigChld(int $exitCode): void
    {
        try {
            $this->drainPipes();
            $this->handleExit($exitCode);
        } finally {
            proc_close($this->process);
        }
    }

    /**
     * @param int $exitCode
     * @return void
     */
    protected function handleExit(int $exitCode): void
    {
        $this->result = $this->buildResult($exitCode);

        if ($exitCode === 0) {
            return;
        }

        $callback = $this->onFailure ?? static function(int $exitCode): bool {
            return in_array($exitCode, ExitCode::defaultFailureCodes(), true);
        };

        if ($callback($exitCode, $this->result)) {
            throw new ProcessFailedException($this->info->command, $exitCode, [
                'shell' => $this,
            ]);
        }
    }

    /**
     * @param resource $src
     * @param FileStream $dest
     * @return string|null
     */
    protected function readPipe(mixed $src, FileStream $dest): ?string
    {
        // If the pipes are closed (They close when the process closes)
        // check if there are any output to be read from `$stdio`,
        // otherwise return **null**.
        if (!is_resource($src)) {
            return $dest->isNotEof()
                ? $dest->readToEnd()
                : null;
        }

        $output = (string) stream_get_contents($src);
        $dest->write($output);
        return $output;
    }

    /**
     * @return void
     */
    protected function drainPipes(): void
    {
        // Read remaining output from the pipes before calling
        // `proc_close(...)`. Otherwise unread data will be lost.
        // The output that has been read here is not read by the
        // user yet, so we seek back to the read position.
        foreach ([1, 2] as $fd) {
            $pipe = $this->pipes[$fd];
            $stdio = $this->stdios[$fd];
            $output = (string) $this->readPipe($pipe, $stdio);
            $stdio->seek(-strlen($output), SEEK_CUR);
        }
    }

    /**
     * @param int $exitCode
     * @return ProcessResult
     */
    protected function buildResult(int $exitCode): ProcessResult
    {
        return new ProcessResult(
            $this->info,
            $this->pid,
            $exitCode,
            $this->stdios[1],
            $this->stdios[2],
        );
    }

    /**
     * @return ProcessResult
     */
    protected function getResult(): ProcessResult
    {
        return $this->result ?? throw new UnreachableException('ProcessResult is not set');
    }
}
