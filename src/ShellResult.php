<?php declare(strict_types=1);

namespace Kirameki\Process;

use Kirameki\Stream\FileStream;

readonly class ShellResult
{
    /**
     * @param ShellInfo $info
     * @param int $pid
     * @param int $exitCode
     * @param FileStream $stdout
     * @param FileStream $stderr
     */
    public function __construct(
        public ShellInfo $info,
        public int $pid,
        public int $exitCode,
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
    public function readStdout(): string
    {
        return $this->stdout->readToEnd();
    }

    /**
     * @return string
     */
    public function readStderr(): string
    {
        return $this->stderr->readToEnd();
    }

    /**
     * @return string
     */
    public function getStdoutOutput(): string
    {
        $stdout = $this->stdout;
        $stdout->seek(0);
        return $stdout->readToEnd();
    }

    /**
     * @return string
     */
    public function getStderrOutput(): string
    {
        $stderr = $this->stderr;
        $stderr->seek(0);
        return $stderr->readToEnd();
    }
}
