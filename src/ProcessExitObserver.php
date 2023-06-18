<?php declare(strict_types=1);

namespace Kirameki\Process;

use Closure;
use Kirameki\Core\Signal;
use Kirameki\Core\SignalEvent;
use Kirameki\Process\Exceptions\ProcessException;
use function array_key_exists;
use const SIGCHLD;

/**
 * @internal
 * @phpstan-consistent-constructor
 */
class ProcessExitObserver
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
     * Observes SIGCHLD signals and invokes registered callbacks.
     * Observation MUST start before any process is spawned.
     * Or else there is a chance a process exits before the observer
     * has a change to register a handler.
     * @return static
     */
    public static function observe(): static
    {
        $self = self::$instance ??= new static();

        // only register signal handler if there are no more signals.
        if (static::$processCount === 0) {
            Signal::handle(SIGCHLD, $self->handleSignal(...));
        }

        static::$processCount++;

        return self::$instance;
    }

    protected function __construct()
    {
    }

    /**
     * @param SignalEvent $event
     * @return void
     */
    protected function handleSignal(SignalEvent $event): void
    {
        static::$processCount--;

        // evict handler if there's no more processes to wait for.
        if (static::$processCount === 0) {
            $event->evictHandler();
        }

        $this->exited($event->info['pid'], $event->info['status']);
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
