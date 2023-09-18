<?php declare(strict_types=1);

namespace Kirameki\Process;

use Closure;
use Kirameki\Core\Exceptions\UnreachableException;
use Kirameki\Core\Signal;
use Kirameki\Core\SignalEvent;
use Kirameki\Process\Exceptions\ProcessException;
use function array_key_exists;
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
     * @var Closure(SignalEvent $event): mixed|null
     */
    protected static ?Closure $signalHandler = null;

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
            static::$signalHandler = $self->handleSignal(...);
            Signal::handle(SIGCHLD, static::$signalHandler);
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
     * WARNING: this method is called even for ALL SIGCHLD signals, which include signals
     * that were not called through ProcessManager (e.g. calling proc_open() directly).
     * Which means that $this->exitedBeforeRegistered may contain exit codes that are not
     * related to ProcessManager. Thus, we will clear this array when all process called
     * through ProcessManager have exited.
     *
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

        if (array_key_exists($pid, $this->exitCallbacks)) {
            $callback = $this->exitCallbacks[$pid];
            unset($this->exitCallbacks[$pid]);
            $this->preProcessExit();
            $callback($exitCode);
        } else {
            // There are some rare cases where the process exits before the ProcessRunner
            // has a chance to register a handler. In that case, store the exit code
            // and invoke the callback when the handler is registered.
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
            // @codeCoverageIgnoreStart
            throw new UnreachableException('Callback already registered for pid: ' . $pid);
            // @codeCoverageIgnoreEnd
        }

        // if the process was already triggered, run the callback immediately.
        if (array_key_exists($pid, $this->exitedBeforeRegistered)) {
            $exitCode = $this->exitedBeforeRegistered[$pid];
            unset($this->exitedBeforeRegistered[$pid]);
            $this->preProcessExit();
            $callback($exitCode);
        } else {
            $this->exitCallbacks[$pid] = $callback;
        }
    }

    protected function preProcessExit(): void
    {
        static::$processCount--;

        if (static::$processCount === 0) {
            if (static::$signalHandler !== null) {
                Signal::clearHandler(SIGCHLD, static::$signalHandler);
                static::$signalHandler = null;
            }
            // We need to explicitly clear this.
            // Read handleSignal()'s comment for more info.
            $this->exitedBeforeRegistered = [];
        }
    }
}
