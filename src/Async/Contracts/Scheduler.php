<?php

declare(strict_types=1);

namespace Glueful\Async\Contracts;

interface Scheduler
{
    public function spawn(callable $fn, ?CancellationToken $token = null): Task;
    /**
     * @param array<int, Task> $tasks
     * @return array<int, mixed>
     */
    public function all(array $tasks): array;
    /** @param array<int, Task> $tasks */
    public function race(array $tasks): mixed;
    public function sleep(float $seconds, ?CancellationToken $token = null): void;
}
