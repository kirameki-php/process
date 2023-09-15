<?php declare(strict_types=1);

namespace Kirameki\Process\Exceptions;

use Kirameki\Core\Json;
use Kirameki\Process\ProcessResult;
use Throwable;
use const PHP_EOL;
use const SIGABRT;
use const SIGALRM;
use const SIGBUS;
use const SIGCLD;
use const SIGCONT;
use const SIGFPE;
use const SIGHUP;
use const SIGILL;
use const SIGINT;
use const SIGKILL;
use const SIGPIPE;
use const SIGPOLL;
use const SIGPROF;
use const SIGPWR;
use const SIGQUIT;
use const SIGSEGV;
use const SIGSTKFLT;
use const SIGSTOP;
use const SIGSYS;
use const SIGTERM;
use const SIGTRAP;
use const SIGTSTP;
use const SIGTTIN;
use const SIGTTOU;
use const SIGURG;
use const SIGUSR1;
use const SIGUSR2;
use const SIGVTALRM;
use const SIGWINCH;
use const SIGXCPU;
use const SIGXFSZ;

class ProcessFailedException extends ProcessException
{

    /**
     * @param string|array<int, string> $command
     * @param int $exitCode
     * @param ProcessResult $result
     * @param iterable<string, mixed>|null $context
     * @param Throwable|null $previous
     */
    public function __construct(
        protected string|array $command,
        protected int $exitCode,
        protected ProcessResult $result,
        ?iterable $context = null,
        ?Throwable $previous = null,
    ) {
        $message = Json::encode($command) . ' ';
        $message .= match (true) {
            $exitCode === 1 => $this->generateCommonMessage('General error'),
            $exitCode === 2 => $this->generateCommonMessage('Misuse of shell builtins'),
            $exitCode === 124 => $this->generateCommonMessage('Timed out'),
            $exitCode === 126 => $this->generateCommonMessage('Permission denied'),
            $exitCode === 127 => $this->generateCommonMessage('Command not found'),
            $exitCode > 128 && $exitCode < 160 => $this->generateSignalMessage(),
            default => $this->generateCommonMessage(),
        };

        if ($stderr = $result->getStderr()) {
            $message .= PHP_EOL . $stderr;
        }

        parent::__construct($message, $context, 0, $previous);

        $this->addContext('exitCode', $exitCode);
        $this->addContext('result', $result);
    }

    /**
     * @return int
     */
    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    protected function generateCommonMessage(string $description = ''): string
    {
        $message = "Exited with code {$this->exitCode}";
        if ($description !== '') {
            $message .= ": {$description}";
        }
        return $message . '.';
    }

    protected function generateSignalMessage(): string
    {
        $number = $this->exitCode - 128;
        $name = $this->signalToName($number);
        return "Terminated by {$name} ({$number})";
    }

    /**
     * @param int $signal
     * @return string
     */
    protected function signalToName(int $signal): string
    {
        return match ($signal) {
            SIGHUP => 'SIGHUP',
            SIGINT => 'SIGINT',
            SIGQUIT => 'SIGQUIT',
            SIGILL => 'SIGILL',
            SIGTRAP => 'SIGTRAP',
            SIGABRT => 'SIGABRT',
            SIGBUS => 'SIGBUS',
            SIGFPE => 'SIGFPE',
            SIGKILL => 'SIGKILL',
            SIGUSR1 => 'SIGUSR1',
            SIGSEGV => 'SIGSEGV',
            SIGUSR2 => 'SIGUSR2',
            SIGPIPE => 'SIGPIPE',
            SIGALRM => 'SIGALRM',
            SIGTERM => 'SIGTERM',
            SIGSTKFLT => 'SIGSTKFLT',
            SIGCLD => 'SIGCLD',
            SIGCONT => 'SIGCONT',
            SIGSTOP => 'SIGSTOP',
            SIGTSTP => 'SIGTSTP',
            SIGTTIN => 'SIGTTIN',
            SIGTTOU => 'SIGTTOU',
            SIGURG => 'SIGURG',
            SIGXCPU => 'SIGXCPU',
            SIGXFSZ => 'SIGXFSZ',
            SIGVTALRM => 'SIGVTALRM',
            SIGPROF => 'SIGPROF',
            SIGWINCH => 'SIGWINCH',
            SIGPOLL => 'SIGPOLL',
            SIGPWR => 'SIGPWR',
            SIGSYS => 'SIGSYS',
            default => "Unknown",
        };
    }
}
