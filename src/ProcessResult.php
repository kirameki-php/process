<?php declare(strict_types=1);

namespace Kirameki\Process;

use Kirameki\Stream\FileReader;
use Kirameki\Stream\FileStream;

readonly class ProcessResult
{
    /**
     * @param ProcessInfo $info
     * @param int $pid
     * @param int $exitCode
     * @param FileStream $stdin
     * @param FileStream $stdout
     * @param FileStream $stderr
     */
    public function __construct(
        public ProcessInfo $info,
        public int $pid,
        public int $exitCode,
        protected FileStream $stdin,
        protected FileStream $stdout,
        protected FileStream $stderr,
    ) {
    }

    /**
     * @return bool
     */
    public function wasSuccessful(): bool
    {
        return $this->exitCode === ExitCode::SUCCESS;
    }

    /**
     * @return bool
     */
    public function didTimeout(): bool
    {
        return $this->exitCode === ExitCode::TIMED_OUT;
    }

    /**
     * @return string
     */
    public function readStdinBuffer(): string
    {
        return $this->stdin->readToEnd();
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

    public function getStdin(): string
    {
        return $this->stdin->rewind()->readToEnd();
    }

    /**
     * @return string
     */
    public function getStdout(): string
    {
        return $this->stdout->rewind()->readToEnd();
    }

    /**
     * @return string
     */
    public function getStderr(): string
    {
        return $this->stderr->rewind()->readToEnd();
    }
}
