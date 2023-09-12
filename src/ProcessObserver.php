<?php declare(strict_types=1);

namespace Kirameki\Process;

use Closure;
use Kirameki\Core\Signal;
use Kirameki\Core\SignalEvent;
use Kirameki\Process\Exceptions\ProcessException;
use function array_key_exists;
use function dump;
use function in_array;
use const CLD_EXITED;
use const CLD_KILLED;
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
    protected array $exitCallbacks = [];

    /**
     * Observation MUST start before any process is spawned or there is a chance
     * a process exits before the observer has a change to register a handler.
     *
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

    /**
     * There should only be one observer instance which is registered
     * through observeSignal, so make this private to make sure
     * people don't initialize this accidentally.
     */
    private function __construct()
    {
    }

    /**
     * @param SignalEvent $event
     * @return void
     */
    protected function handleSignal(SignalEvent $event): void
    {
        /** @var array{ pid: int, status: int, code: int } $info */
        $info = $event->info;

        // Only respond to exit and kill code.
        if (!in_array($info['code'], [CLD_EXITED, CLD_KILLED], true)) {
            return;
        }

        $pid = $info['pid'];
        $exitCode = $info['status'];
        if ($info['code'] === CLD_KILLED) {
            $exitCode += 128;
        }

        static::$processCount--;

        // evict handler if there's no more processes to wait for.
        if (static::$processCount === 0) {
            $event->evictCallback();
        }

        if (array_key_exists($pid, $this->exitCallbacks)) {
            ($this->exitCallbacks[$pid])($exitCode);
            unset($this->exitCallbacks[$pid]);
        } else {
            $this->exitedBeforeRegistered[$pid] = $exitCode;
        }
    }

    /**
     * @param int $pid
     * @param Closure(int): void $callback
     * @return void
     */
    public function onExit(int $pid, Closure $callback): void
    {
        if (array_key_exists($pid, $this->exitCallbacks)) {
            throw new ProcessException('Callback already registered for pid: ' . $pid);
        }

        // if the process was already triggered, run the callback immediately.
        if (array_key_exists($pid, $this->exitedBeforeRegistered)) {
            $exitCode = $this->exitedBeforeRegistered[$pid];
            unset($this->exitedBeforeRegistered[$pid]);
            $callback($exitCode);
        } else {
            $this->exitCallbacks[$pid] = $callback;
        }
    }
}
