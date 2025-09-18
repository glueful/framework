<?php

declare(strict_types=1);

namespace Glueful\Events\Tracing;

interface EventTracerInterface
{
    public function startEvent(string $eventClass, int $listenerCount): void;
    public function listenerDone(string $eventClass, callable $listener, int $durationNs): void;
    public function listenerError(string $eventClass, callable $listener, \Throwable $e): void;
    public function endEvent(string $eventClass): void;
}
