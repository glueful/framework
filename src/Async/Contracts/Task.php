<?php

declare(strict_types=1);

namespace Glueful\Async\Contracts;

interface Task
{
    public function isRunning(): bool;
    public function isCompleted(): bool;
    public function getResult(): mixed; // May throw \Throwable
    public function cancel(): void;
}
