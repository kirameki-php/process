<?php declare(strict_types=1);

namespace Kirameki\Process;

use Kirameki\Stream\FileStream;

readonly class ShellResult
{
    /**
     * @param ShellInfo $info
     * @param array{ pid: int } $status
     * @param FileStream $stdout
     * @param FileStream $stderr
     * @param int $exitCode
     */
    public function __construct(
        public ShellInfo $info,
        protected array $status,
        protected FileStream $stdout,
        protected FileStream $stderr,
        public int $exitCode,
    ) {
    }

    /**
     * @return bool
     */
    public function wasSuccessful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * @return bool
     */
    public function didTimeout(): bool
    {
        return $this->exitCode === 124;
    }
}
