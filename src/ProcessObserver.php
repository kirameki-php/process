<?php declare(strict_types=1);

namespace Kirameki\Process;

use Closure;
use Kirameki\Core\Signal;
use Kirameki\Core\SignalEvent;
use Kirameki\Process\Exceptions\ProcessException;
use function array_key_exists;
use function array_keys;
use function dump;
use function pcntl_async_signals;
use function pcntl_signal;
use const SIGCHLD;

/**
 * Observes SIGCHLD signals and invokes registered callbacks.
 * If a process exits before the observer has a change to register a handler,
 * the exit code is stored and the callback is invoked when the observer is registered.
 *
 * @internal
 * @phpstan-consistent-constructor
 */
class ProcessObserver
{
    /**
     * @var static
     */
    protected static self $instance;

    /**
     * @var int
     */
    protected static int $processCount = 0;

    /**
     * @var array<int, int> [pid => exitCode]
     */
    protected array $exitedBeforeRegistered = [];

    /**
     * @var array<int, Closure(int): void>
     */
    protected array $registered = [];

    /**
     * Observation MUST start before any process is spawned or there is a chance
     * a process exits before the observer has a change to register a handler.
     *
     * @return static
     */
    public static function observeSignal(): static
    {
        $self = self::$instance ??= new static();

        // only register signal handler if there are no more signals.
        if (static::$processCount === 0) {
            pcntl_async_signals(true);
            //Signal::handle(SIGCHLD, $self->handleSignal(...));
            pcntl_signal(SIGCHLD, function($info) {
                dump($info);
            }, false);
        }

        static::$processCount++;

        return self::$instance;
    }

    /**
     * There should only be one observer instance which is registered
     * through observeSignal, so make this protected to make sure
     * people don't initialize this accidentally.
     */
    protected function __construct()
    {
    }

    /**
     * @param SignalEvent $event
     * @return void
     */
    protected function handleSignal(SignalEvent $event): void
    {
        dump($event->info['pid']);
        dump(static::$processCount);
        dump(array_keys($this->registered));

        static::$processCount--;

        // evict handler if there's no more processes to wait for.
        if (static::$processCount === 0) {
            $event->evictHandler();
        }
//
//        $info = $event->info;
//
//        $pid = $info['pid'];
//
//        $exitCode = $info['status'];
//        if ($info['code'] === 2) {
//            $exitCode += 128;
//        }
//
    // $this->exited($pid, $exitCode);
    }

    /**
     * @param int $pid
     * @param int $exitCode
     * @return void
     */
    protected function exited(int $pid, int $exitCode): void
    {
        if ($this->alreadyExited($pid)) {
            throw new ProcessException('pid: ' . $pid . ' already triggered.');
        }

        if ($this->callbackRegistered($pid)) {
            $this->invokeAndDeregister($pid, $exitCode);
        } else {
            $this->exitedBeforeRegistered[$pid] = $exitCode;
        }
    }

    /**
     * @param int $pid
     * @param Closure(int): void $callback
     * @return void
     */
    public function onSignal(int $pid, Closure $callback): void
    {
        if ($this->callbackRegistered($pid)) {
            throw new ProcessException('Callback already registered for pid: ' . $pid);
        }

        $this->registered[$pid] = $callback;

        // if the process was already triggered, run the callback immediately.
        if ($this->alreadyExited($pid)) {
            $exitCode = $this->exitedBeforeRegistered[$pid];
            $this->invokeAndDeregister($pid, $exitCode);
        }
    }

    /**
     * @param int $pid
     * @return bool
     */
    protected function alreadyExited(int $pid): bool
    {
        return array_key_exists($pid, $this->exitedBeforeRegistered);
    }

    /**
     * @param int $pid
     * @return bool
     */
    protected function callbackRegistered(int $pid): bool
    {
        return array_key_exists($pid, $this->registered);
    }

    /**
     * @param int $pid
     * @return void
     */
    protected function invokeAndDeregister(int $pid, int $exitCode): void
    {
        ($this->registered[$pid])($exitCode);
        unset($this->registered[$pid]);
    }
}
