<?php

declare(strict_types=1);

namespace Glueful\Async\Task;

use Glueful\Async\Contracts\Task;

final class CompletedTask implements Task
{
    public function __construct(private mixed $value)
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
        return $this->value;
    }

    public function cancel(): void
    {
    }
}
