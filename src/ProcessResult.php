<?php declare(strict_types=1);

namespace Kirameki\Process;

use Kirameki\Stream\FileStream;

readonly class ProcessResult
{
    /**
     * @param ProcessInfo $info
     * @param int $exitCode
     * @param FileStream|null $stdin
     * @param FileStream $stdout
     * @param FileStream $stderr
     */
    public function __construct(
        public ProcessInfo $info,
        public int $exitCode,
        protected ?FileStream $stdin,
        protected FileStream $stdout,
        protected FileStream $stderr,
    )
    {
    }

    /**
     * @return bool
     */
    public function succeeded(): bool
    {
        return $this->exitCode === ExitCode::SUCCESS;
    }

    /**
     * @return bool
     */
    public function failed(): bool
    {
        return !$this->succeeded();
    }

    /**
     * @return bool
     */
    public function timedOut(): bool
    {
        return $this->exitCode === ExitCode::TIMED_OUT;
    }

    /**
     * @return string
     */
    public function readStdoutBuffer(): string
    {
        return $this->stdout->readToEnd();
    }

    /**
     * @return string
     */
    public function readStderrBuffer(): string
    {
        return $this->stderr->readToEnd();
    }

    /**
     * @return string
     */
    public function getStdin(): string
    {
        return $this->stdin?->readFromStartToEnd() ?? '';
    }

    /**
     * @return string
     */
    public function getStdout(): string
    {
        return $this->stdout->readFromStartToEnd();
    }

    /**
     * @return string
     */
    public function getStderr(): string
    {
        return $this->stderr->readFromStartToEnd();
    }
}
