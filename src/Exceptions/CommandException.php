<?php declare(strict_types=1);

namespace Kirameki\Process\Exceptions;

use Kirameki\Core\Exceptions\RuntimeException;
use Throwable;

class CommandException extends RuntimeException
{
    public function __construct(protected int $exitCode, ?iterable $context = null, ?Throwable $previous = null)
    {
        parent::__construct($message, $context, 0, $previous);
    }

    /**
     * @return int
     */
    public function getExitCode(): int
    {
        return $this->exitCode;
    }
}
