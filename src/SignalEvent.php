<?php declare(strict_types=1);

namespace Kirameki\Process;

use Kirameki\Event\Event;

class SignalEvent extends Event
{
    /**
     * @param int $signal
     * @param mixed $info
     * @param bool $terminate
     */
    public function __construct(
        public readonly int $signal,
        public readonly mixed $info,
        protected bool $terminate,
    ) {
    }

    /**
     * Mark signal for termination.
     * When this is set to **true**, the application will exit after
     * all the signal callbacks have been processed.
     *
     * @param bool $toggle
     * [Optional] Toggles termination.
     * Defaults to **true**.
     * @return $this
     */
    public function shouldTerminate(bool $toggle = true): static
    {
        $this->terminate = $toggle;
        return $this;
    }

    /**
     * Returns whether the signal is marked for termination.
     *
     * @return bool
     */
    public function markedForTermination(): bool
    {
        return $this->terminate;
    }
}
