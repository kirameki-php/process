<?php declare(strict_types=1);

namespace Kirameki\Process;

use const SIGHUP;
use const SIGINT;
use const SIGKILL;
use const SIGQUIT;
use const SIGSEGV;
use const SIGTERM;
use const SIGUSR1;
use const SIGUSR2;

class ExitCode
{
    public const SUCCESS = 0;
    public const GENERAL_ERROR = 1;
    public const TIMEOUT = 124;
    public const COMMAND_NOT_FOUND = 127;
    public const SIGKILL = 128 + SIGKILL;
    public const SIGSEGV = 128 + SIGSEGV;
    public const SIGTERM = 128 + SIGTERM;
}
