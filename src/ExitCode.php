<?php declare(strict_types=1);

namespace Kirameki\Process;

class ExitCode
{
    public const SUCCESS = 0;
    public const GENERAL_ERROR = 1;
    public const INVALID_USAGE = 2;
    public const TIMED_OUT = 124;
    public const TIMEOUT_COMMAND_FAILED = 125;
    public const COMMAND_NOT_EXECUTABLE = 126;
    public const COMMAND_NOT_FOUND = 127;
    public const SIGHUP = 129;
    public const SIGINT = 130;
    public const SIGKILL = 137;
    public const SIGSEGV = 139;
    public const SIGTERM = 143;

    /**
     * @return list<int>
     */
    public static function defaultFailureCodes(): array
    {
        return [
            ExitCode::GENERAL_ERROR,
            ExitCode::INVALID_USAGE,
            ExitCode::TIMED_OUT,
            ExitCode::TIMEOUT_COMMAND_FAILED,
            ExitCode::COMMAND_NOT_EXECUTABLE,
            ExitCode::COMMAND_NOT_FOUND,
            ExitCode::SIGSEGV,
            ExitCode::SIGKILL,
        ];
    }
}
