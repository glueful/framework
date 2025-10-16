<?php

declare(strict_types=1);

namespace Glueful\Async\Task;

use Glueful\Async\Contracts\Task;
use Glueful\Async\Instrumentation\Metrics;
use Glueful\Async\Instrumentation\NullMetrics;

final class ClosureTask implements Task
{
    private \Closure $closure;
    private bool $executed = false;
    private mixed $result = null;
    private ?\Throwable $error = null;
    private Metrics $metrics;
    private ?string $name;

    public function __construct(callable $fn, ?Metrics $metrics = null, ?string $name = null)
    {
        $this->closure = \Closure::fromCallable($fn);
        $this->metrics = $metrics ?? new NullMetrics();
        $this->name = $name;
    }

    public function isRunning(): bool
    {
        return !$this->executed;
    }

    public function isCompleted(): bool
    {
        return $this->executed;
    }

    public function getResult(): mixed
    {
        if (!$this->executed) {
            $taskName = $this->name ?? $this->inferName();
            $this->metrics->taskStarted($taskName);
            try {
                $this->result = ($this->closure)();
                $this->metrics->taskCompleted($taskName);
            } catch (\Throwable $e) {
                $this->error = $e;
                $this->metrics->taskFailed($taskName, $e);
            } finally {
                $this->executed = true;
            }
        }
        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->result;
    }

    public function cancel(): void
    {
    }

    private function inferName(): string
    {
        $ref = new \ReflectionFunction($this->closure);
        if ($ref->isClosure()) {
            $fileName = $ref->getFileName();
            $file = basename($fileName !== false ? $fileName : 'closure');
            $line = $ref->getStartLine();
            return 'closure@' . $file . ':' . $line;
        }
        return 'task';
    }
}
