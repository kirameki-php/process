<?php declare(strict_types=1);

namespace Kirameki\Process;

use Closure;
use Kirameki\Core\Exceptions\LogicException;
use Kirameki\Core\Exceptions\UnreachableException;
use Kirameki\Core\StaticClass;
use Kirameki\Event\EventHandler;
use Kirameki\Event\Listeners\CallbackListener;
use Kirameki\Event\Listeners\EventListener;
use function array_keys;
use function compact;
use function in_array;
use function pcntl_async_signals;
use function pcntl_signal;
use function pcntl_wait;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function pcntl_wifsignaled;
use function pcntl_wifstopped;
use function pcntl_wtermsig;
use const CLD_EXITED;
use const CLD_KILLED;
use const CLD_STOPPED;
use const SIG_DFL;
use const SIGABRT;
use const SIGALRM;
use const SIGBUS;
use const SIGCHLD;
use const SIGCONT;
use const SIGFPE;
use const SIGHUP;
use const SIGILL;
use const SIGINT;
use const SIGKILL;
use const SIGPIPE;
use const SIGPOLL;
use const SIGPROF;
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
use const SIGUSR1;
use const SIGUSR2;
use const SIGVTALRM;
use const SIGWINCH;
use const SIGXCPU;
use const SIGXFSZ;
use const WNOHANG;
use const WUNTRACED;

final class Signal extends StaticClass
{
    /**
     * @see https://www.gnu.org/software/libc/manual/html_node/Termination-Signals.html
     */
    public final const TermSignals = [
        SIGHUP,  // 1
        SIGINT,  // 2
        SIGQUIT, // 3
        SIGTERM, // 15
    ];

    /**
     * @var array<int, EventHandler<SignalEvent>>
     */
    private static array $callbacks = [];

    /**
     * Adds `$callback` to the signal handler.
     *
     * @param int $signal
     * Signal number to handle.
     * @param Closure(SignalEvent): mixed $callback
     * Callback to be invoked when the signal is received.
     * @return CallbackListener<SignalEvent>
     */
    public static function handle(int $signal, Closure $callback): CallbackListener
    {
        return self::addListener($signal, new CallbackListener($callback));
    }

    /**
     * @template TListener of EventListener<SignalEvent>
     * @param int $signal
     * @param TListener $listener
     * @return TListener
     */
    public static function addListener(int $signal, EventListener $listener): EventListener
    {
        if ($signal === SIGKILL || $signal === SIGSEGV) {
            throw new LogicException('SIGKILL and SIGSEGV cannot be captured.', [
                'signal' => $signal,
                'listener' => $listener,
            ]);
        }

        // Set async on once.
        if (self::$callbacks === []) {
            pcntl_async_signals(true);
        }

        if (!isset(self::$callbacks[$signal])) {
            self::captureSignal($signal);
        }

        self::$callbacks[$signal] ??= new EventHandler(SignalEvent::class);
        self::$callbacks[$signal]->append($listener);

        return $listener;
    }

    /**
     * Register a callback for the given signal which will call invoke() when the signal is received.
     *
     * @param int $signal
     * Signal number to be invoked.
     * @return void
     */
    protected static function captureSignal(int $signal): void
    {
        pcntl_signal($signal, function($sig, array $info) {
            // SIGCHLD needs special handling.
            // @see https://github.com/php/php-src/pull/11509
            if ($sig === SIGCHLD) {
                while(true) {
                    $pid = pcntl_wait($waitInfo, WUNTRACED | WNOHANG);
                    if ($pid > 0) {
                        if (pcntl_wifexited($waitInfo)) {
                            $status = pcntl_wexitstatus($waitInfo);
                            $code = CLD_EXITED;
                        } elseif (pcntl_wifsignaled($waitInfo)) {
                            $status = pcntl_wtermsig($waitInfo);
                            $code = CLD_KILLED;
                        } elseif (pcntl_wifstopped($waitInfo)) {
                            $status = SIGSTOP;
                            $code = CLD_STOPPED;
                        } else {
                            continue;
                        }
                        self::invoke($sig, compact('pid', 'status', 'code'));
                    } else {
                        break;
                    }
                }
            } else {
                self::invoke($sig, $info);
            }
        });
    }

    /**
     * Invokes all callbacks for the given signal.
     * If the signal is marked for termination, this process will exit
     * with the given (signal number + 128) as specified in
     * https://tldp.org/LDP/abs/html/exitcodes.html
     *
     * @param int $signal
     * Signal number to be invoked.
     * @param mixed $sigInfo
     * Information about the signal from `pcntl_signal(...)`.
     * @return void
     */
    protected static function invoke(int $signal, mixed $sigInfo): void
    {
        if (!isset(self::$callbacks[$signal])) {
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $event = self::createSignalEvent($signal, $sigInfo);

        self::$callbacks[$signal]->emit($event);

        // Must check that signal exists again because the emitted callbacks may have removed it
        // by calling Signal::clearHandler().
        if (isset(self::$callbacks[$signal]) && self::$callbacks[$signal]->hasNoListeners()) {
            unset(self::$callbacks[$signal]);
            pcntl_signal($signal, SIG_DFL);
        }

        if ($event->markedForTermination()) {
            /** @see https://tldp.org/LDP/abs/html/exitcodes.html **/
            // @codeCoverageIgnoreStart
            exit(128 + $signal);
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * Returns all the registered signals.
     *
     * @return array<int, int>
     */
    public static function registeredSignals(): array
    {
        return array_keys(self::$callbacks);
    }

    /**
     * Clear the given `$callback` for the specified signal.
     * Returns the number of callbacks removed.
     *
     * @param int $signal
     * @param EventListener<SignalEvent> $listener
     * @return int
     */
    public static function clearHandler(int $signal, EventListener $listener): int
    {
        if (!isset(self::$callbacks[$signal])) {
            return 0;
        }

        $result = self::$callbacks[$signal]->removeListener($listener);

        if (self::$callbacks[$signal]->hasNoListeners()) {
            self::clearHandlers($signal);
        }

        return $result;
    }

    /**
     * Clears the signal handlers for the specified signal.
     *
     * @param int $signal
     * Signal to clear.
     * @return bool
     */
    public static function clearHandlers(int $signal): bool
    {
        if (!isset(self::$callbacks[$signal])) {
            return false;
        }

        // Clear all handlers.
        unset(self::$callbacks[$signal]);
        pcntl_signal($signal, SIG_DFL);
        return true;
    }

    /**
     * Clears all the signal handlers.
     *
     * @return void
     */
    public static function clearAllHandlers(): void
    {
        foreach (self::registeredSignals() as $signal) {
            self::clearHandlers($signal);
        }
    }

    /**
     * Get the name of the signal from the signal number.
     *
     * @param int<1, 31> $signal
     * @return string
     */
    public static function getNameOf(int $signal): string
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
            SIGCHLD => 'SIGCHLD',
            SIGCONT => 'SIGCONT',
            SIGSTOP => 'SIGSTOP',
            SIGTSTP => 'SIGTSTP',
            SIGTTIN => 'SIGTTIN',
            SIGTTOU => 'SIGTTOU',
            SIGXCPU => 'SIGXCPU',
            SIGXFSZ => 'SIGXFSZ',
            SIGVTALRM => 'SIGVTALRM',
            SIGPROF => 'SIGPROF',
            SIGWINCH => 'SIGWINCH',
            SIGPOLL => 'SIGPOLL',
            SIGSYS => 'SIGSYS',
            default => throw new UnreachableException("Unknown signal: {$signal}"),
        };
    }

    /**
     * Creates a new signal event.
     * Event will be marked for termination if the signal is a termination signal.
     *
     * @param int $signal
     * Signal number to be set.
     * @param mixed $siginfo
     * Signal information from `pcntl_signal(...)` to be set.
     * @return SignalEvent
     */
    protected static function createSignalEvent(int $signal, mixed $siginfo): SignalEvent
    {
        return new SignalEvent(
            $signal,
            $siginfo,
            in_array($signal, self::TermSignals, true),
        );
    }
}
