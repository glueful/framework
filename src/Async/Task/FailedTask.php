<?php

declare(strict_types=1);

namespace Glueful\Async\Task;

use Glueful\Async\Contracts\Task;

final class FailedTask implements Task
{
    public function __construct(private \Throwable $e)
    {
    }

    public function isRunning(): bool
    {
        return false;
    }

    public function isCompleted(): bool
    {
        return true;
    }

    public function getResult(): mixed
    {
        throw $this->e;
    }

    public function cancel(): void
    {
    }
}
