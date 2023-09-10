<?php declare(strict_types=1);

namespace Kirameki\Process;

use Closure;
use IteratorAggregate;
use Kirameki\Core\EventHandler;
use Kirameki\Core\Exceptions\UnreachableException;
use Kirameki\Process\Events\ProcessFinished;
use Kirameki\Process\Exceptions\ProcessFailedException;
use Kirameki\Stream\FileStream;
use Kirameki\Stream\TempStream;
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
     * @var FileStream|null
     */
    protected ?FileStream $stdin = null;

    /**
     * @var FileStream
     */
    protected FileStream $stdout;

    /**
     * @var FileStream
     */
    protected FileStream $stderr;

    /**
     * @param resource $process
     * @param ProcessObserver $observer
     * @param ProcessInfo $info
     * @param array<int, resource> $pipes
     * @param EventHandler<ProcessFinished> $onFinished
     * @param ProcessResult|null $result
     */
    public function __construct(
        protected readonly mixed $process,
        protected ProcessObserver $observer,
        public readonly ProcessInfo $info,
        protected readonly array $pipes,
        protected readonly ?EventHandler $onFinished,
        protected ?ProcessResult $result = null,
    )
    {
        $this->stdout = $this->makeStdioStream();
        $this->stderr = $this->makeStdioStream();

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                stream_set_blocking($pipe, false);
            }
        }

        $observer->onExit($this->info->pid, $this->onSigChld(...));
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
                if (($stdout = $this->readStdoutBuffer()) !== '') {
                    yield 1 => $stdout;
                }
                if (($stderr = $this->readStderrBuffer()) !== '') {
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

        $this->stdin ??= $this->makeStdioStream();
        $this->stdin->write($input);

        $length = fwrite($this->pipes[0], $input);

        return is_int($length);
    }

    /**
     * @return string
     */
    public function readStdoutBuffer(): string
    {
        return $this->readPipe($this->pipes[1], $this->stdout);
    }

    /**
     * @return string
     */
    public function readStderrBuffer(): string
    {
        return $this->readPipe($this->pipes[2], $this->stderr);
    }

    /**
     * @param int $exitCode
     * @return void
     */
    protected function onSigChld(int $exitCode): void
    {
        try {
            $this->drainPipes();
            $this->handleExit($exitCode);
        } finally {
            proc_close($this->process);
        }
    }

    /**
     * @param int $code
     * @return void
     */
    protected function handleExit(int $code): void
    {
        $this->onFinished?->dispatch(new ProcessFinished($this->info, $code));

        $result = $this->result = $this->buildResult($code);

        if ($code === ExitCode::SUCCESS || in_array($code, $this->info->exceptedExitCodes, true)) {
            return;
        }

        throw new ProcessFailedException($this->info->definedCommand, $code, [
            'result' => $result,
        ]);
    }

    /**
     * @param resource $pipe
     * @param FileStream $buffer
     * @return string
     */
    protected function readPipe(mixed $pipe, FileStream $buffer): string
    {
        // If the pipes are closed (They close when the process closes)
        // check if there are any output to be read from `$stdio`,
        // otherwise return **null**.
        if (!is_resource($pipe)) {
            return $buffer->readToEnd();
        }

        $output = (string) stream_get_contents($pipe);
        $buffer->write($output);
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
        foreach ([1 => $this->stdout, 2 => $this->stderr] as $fd => $stdio) {
            $pipe = $this->pipes[$fd];
            $output = $this->readPipe($pipe, $stdio);
            $stdio->seek(-strlen($output), SEEK_CUR);
        }
    }

    /**
     * @return FileStream
     */
    protected function makeStdioStream(): FileStream
    {
        return new TempStream();
    }

    /**
     * @param int $exitCode
     * @return ProcessResult
     */
    protected function buildResult(int $exitCode): ProcessResult
    {
        return new ProcessResult(
            $this->info,
            $exitCode,
            $this->stdin,
            $this->stdout,
            $this->stderr,
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
