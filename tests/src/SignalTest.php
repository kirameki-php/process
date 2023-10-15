<?php declare(strict_types=1);

namespace Tests\Kirameki\Process;

use Kirameki\Core\Exceptions\LogicException;
use Kirameki\Core\Exceptions\NotSupportedException;
use Kirameki\Core\Exceptions\UnreachableException;
use Kirameki\Core\Testing\TestCase;
use Kirameki\Process\Signal;
use Kirameki\Process\SignalEvent;
use PHPUnit\Framework\Attributes\Before;
use function getmypid;
use function posix_kill;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function usleep;
use const CLD_EXITED;
use const CLD_KILLED;
use const CLD_STOPPED;
use const SIGCHLD;
use const SIGINT;
use const SIGKILL;
use const SIGSEGV;
use const SIGSTOP;
use const SIGUSR1;

final class SignalTest extends TestCase
{
    #[Before]
    public function clearHandlers(): void
    {
        Signal::clearAllHandlers();
    }

    public function test_instantiate(): void
    {
        $this->expectExceptionMessage('Cannot instantiate static class: Kirameki\Process\Signal');
        $this->expectException(NotSupportedException::class);
        new Signal();
    }

    public function test_handle_signal(): void
    {
        $event = null;
        Signal::handle(SIGUSR1, static function(SignalEvent $e) use (&$event) {
            $event = $e;
        });

        posix_kill((int) getmypid(), SIGUSR1);

        $this->assertInstanceOf(SignalEvent::class, $event);
        $this->assertSame(SIGUSR1, $event->signal);
        $this->assertFalse($event->markedForTermination());
        $this->assertSame(getmypid(), $event->info['pid']);
        $this->assertSame([SIGUSR1], Signal::registeredSignals());
    }

    public function test_handle_SIGCHLD_exited(): void
    {
        $event = null;
        Signal::handle(SIGCHLD, static function(SignalEvent $e) use (&$event) {
            $event = $e;
        });
        $proc = proc_open('exit 1', [], $pipes) ?: throw new UnreachableException();
        $info = proc_get_status($proc);
        while ($event === null) {
            usleep(1000);
        }
        $this->assertSame($event->signal, SIGCHLD);
        $this->assertSame($info['pid'], $event->info['pid']);
        $this->assertSame(1, $event->info['status'] ?? 0);
        $this->assertSame(CLD_EXITED, $event?->info['code'] ?? 0);
    }

    public function test_handle_SIGCHLD_killed(): void
    {
        $event = null;
        Signal::handle(SIGCHLD, static function(SignalEvent $e) use (&$event) {
            $event = $e;
        });
        $proc = proc_open('sleep 3', [], $pipes) ?: throw new UnreachableException();
        proc_terminate($proc, SIGKILL);
        $info = proc_get_status($proc);
        while ($event === null) {
            usleep(1000);
        }
        $this->assertSame($event->signal, SIGCHLD);
        $this->assertSame($info['pid'], $event->info['pid']);
        $this->assertSame(SIGKILL, $event->info['status'] ?? 0);
        $this->assertSame(CLD_KILLED, $event->info['code'] ?? 0);
    }

    public function test_handle_SIGCHLD_stopped(): void
    {
        $event = null;
        Signal::handle(SIGCHLD, static function(SignalEvent $e) use (&$event) {
            $event = $e;
        });
        $proc = proc_open('sleep 0.1', [], $pipes) ?: throw new UnreachableException();
        proc_terminate($proc, SIGSTOP);
        $this->runBeforeTearDown(static fn() => proc_terminate($proc, SIGKILL));
        $info = proc_get_status($proc);
        while ($event === null) {
            usleep(1000);
        }
        $this->assertSame($event->signal, SIGCHLD);
        $this->assertSame($info['pid'], $event->info['pid']);
        $this->assertSame(SIGSTOP, $event->info['status'] ?? 0);
        $this->assertSame(CLD_STOPPED, $event->info['code'] ?? 0);
    }

    public function test_invoke_non_registered(): void
    {
        $event = null;
        Signal::handle(SIGUSR1, static function(SignalEvent $e) use (&$event) {
            $event = $e;
        });
        $proc = proc_open('exit 1', [], $pipes);
        proc_close($proc);
        $this->assertNull($event);
    }

    public function test_handle_signal_with_term_signals(): void
    {
        foreach (Signal::TermSignals as $signal) {
            $event = null;
            $terminates = false;
            Signal::handle($signal, static function(SignalEvent $e) use (&$event, &$terminates) {
                $event = $e;
                $terminates = $e->markedForTermination();
                $e->shouldTerminate(false);
            });

            posix_kill((int) getmypid(), $signal);

            $this->assertInstanceOf(SignalEvent::class, $event);
            $this->assertSame($signal, $event->signal);
            $this->assertTrue($terminates);
        }
        $this->assertSame(Signal::TermSignals, Signal::registeredSignals());
    }

    public function test_handle_with_kill_signal(): void
    {
        $this->expectExceptionMessage('SIGKILL and SIGSEGV cannot be captured.');
        $this->expectException(LogicException::class);

        Signal::handle(SIGKILL, static fn() => null);
    }

    public function test_handle_with_segfault_signal(): void
    {
        $this->expectExceptionMessage('SIGKILL and SIGSEGV cannot be captured.');
        $this->expectException(LogicException::class);

        Signal::handle(SIGSEGV, static fn() => null);
    }

    public function test_handle_with_eviction(): void
    {
        $count = 0;
        $e1 = null;
        Signal::handle(SIGUSR1, static function(SignalEvent $e) use (&$count, &$e1) {
            $e1 = clone $e->evictCallback();
            $count++;
        });

        $e2 = null;
        Signal::handle(SIGUSR1, static function(SignalEvent $e) use (&$count, &$e2) {
            $e2 = clone $e->evictCallback();
            $count++;
        });

        $this->assertSame([SIGUSR1], Signal::registeredSignals());

        posix_kill((int) getmypid(), SIGUSR1);

        $this->assertTrue($e1?->willEvictCallback());
        $this->assertTrue($e2?->willEvictCallback());
        $this->assertSame(2, $count);
        $this->assertSame([], Signal::registeredSignals());
    }

    public function test_handle_with_partial_eviction(): void
    {
        $count = 0;
        $e1 = null;
        Signal::handle(SIGUSR1, static function(SignalEvent $e) use (&$count, &$e1) {
            $e1 = clone $e->evictCallback();
            $count++;
        });

        $e2 = null;
        Signal::handle(SIGUSR1, static function(SignalEvent $e) use (&$count, &$e2) {
            $e2 = clone $e;
            $count++;
        });

        posix_kill((int) getmypid(), SIGUSR1);

        $this->assertTrue($e1?->willEvictCallback());
        $this->assertFalse($e2?->willEvictCallback());
        $this->assertSame([SIGUSR1], Signal::registeredSignals());

        posix_kill((int) getmypid(), SIGUSR1);

        $this->assertSame([SIGUSR1], Signal::registeredSignals());
    }

    public function test_registeredSignals(): void
    {
        $this->assertSame([], Signal::registeredSignals());
        Signal::handle(SIGINT, static fn() => null);
        $this->assertSame([SIGINT], Signal::registeredSignals());
    }

    public function test_clearHandler(): void
    {
        $callback = static fn() => null;
        Signal::handle(SIGINT, $callback);
        Signal::handle(SIGUSR1, $callback);

        $this->assertSame([SIGINT, SIGUSR1], Signal::registeredSignals());
        $this->assertSame(1, Signal::clearHandler(SIGUSR1, $callback));
        $this->assertSame(0, Signal::clearHandler(SIGUSR1, $callback));
        $this->assertSame(0, Signal::clearHandler(SIGINT, static fn() => null));
        $this->assertSame([SIGINT], Signal::registeredSignals());
    }

    public function test_clearHandler_within_event_callback(): void
    {
        $callback = static function() use (&$callback) {
            Signal::clearHandler(SIGUSR1, $callback);
        };
        Signal::handle(SIGUSR1, $callback);

        posix_kill((int) getmypid(), SIGUSR1);

        $this->assertSame([], Signal::registeredSignals());
    }

    public function test_clearHandlers(): void
    {
        $callback = static fn() => null;
        Signal::handle(SIGINT, $callback);
        Signal::handle(SIGUSR1, $callback);
        $this->assertSame([SIGINT, SIGUSR1], Signal::registeredSignals());
        $this->assertTrue(Signal::clearHandlers(SIGUSR1));
        $this->assertSame([SIGINT], Signal::registeredSignals());
    }

    public function test_clearHandlers_non_existing_signal(): void
    {
        $this->assertFalse(Signal::clearHandlers(SIGINT));
        $this->assertSame([], Signal::registeredSignals());
    }

    public function test_getNameOf(): void
    {
        $this->assertSame('SIGHUP', Signal::getNameOf(SIGHUP));
        $this->assertSame('SIGINT', Signal::getNameOf(SIGINT));
        $this->assertSame('SIGQUIT', Signal::getNameOf(SIGQUIT));
        $this->assertSame('SIGILL', Signal::getNameOf(SIGILL));
        $this->assertSame('SIGTRAP', Signal::getNameOf(SIGTRAP));
        $this->assertSame('SIGABRT', Signal::getNameOf(SIGABRT));
        $this->assertSame('SIGBUS', Signal::getNameOf(SIGBUS));
        $this->assertSame('SIGFPE', Signal::getNameOf(SIGFPE));
        $this->assertSame('SIGKILL', Signal::getNameOf(SIGKILL));
        $this->assertSame('SIGUSR1', Signal::getNameOf(SIGUSR1));
        $this->assertSame('SIGSEGV', Signal::getNameOf(SIGSEGV));
        $this->assertSame('SIGUSR2', Signal::getNameOf(SIGUSR2));
        $this->assertSame('SIGPIPE', Signal::getNameOf(SIGPIPE));
        $this->assertSame('SIGALRM', Signal::getNameOf(SIGALRM));
        $this->assertSame('SIGTERM', Signal::getNameOf(SIGTERM));
        $this->assertSame('SIGSTKFLT', Signal::getNameOf(SIGSTKFLT));
        $this->assertSame('SIGCHLD', Signal::getNameOf(SIGCHLD));
        $this->assertSame('SIGCONT', Signal::getNameOf(SIGCONT));
        $this->assertSame('SIGSTOP', Signal::getNameOf(SIGSTOP));
        $this->assertSame('SIGTSTP', Signal::getNameOf(SIGTSTP));
        $this->assertSame('SIGTTIN', Signal::getNameOf(SIGTTIN));
        $this->assertSame('SIGTTOU', Signal::getNameOf(SIGTTOU));
        $this->assertSame('SIGXCPU', Signal::getNameOf(SIGXCPU));
        $this->assertSame('SIGXFSZ', Signal::getNameOf(SIGXFSZ));
        $this->assertSame('SIGVTALRM', Signal::getNameOf(SIGVTALRM));
        $this->assertSame('SIGPROF', Signal::getNameOf(SIGPROF));
        $this->assertSame('SIGWINCH', Signal::getNameOf(SIGWINCH));
        $this->assertSame('SIGPOLL', Signal::getNameOf(SIGPOLL));
        $this->assertSame('SIGSYS', Signal::getNameOf(SIGSYS));
    }

    public function test_getNameOf_non_existing_signal(): void
    {
        $this->expectExceptionMessage('Unknown signal: 32');
        $this->expectException(UnreachableException::class);
        Signal::getNameOf(32);
    }
}
