<?php

declare(strict_types=1);

namespace Glueful\Async\Task;

use Glueful\Async\Contracts\Task;
use Glueful\Async\Instrumentation\Metrics;
use Glueful\Async\Instrumentation\NullMetrics;
use Glueful\Async\Internal\SleepOp;

final class FiberTask implements Task
{
    private \Closure $closure;

    /** @var \Fiber<mixed, mixed, mixed, mixed>|null */
    private ?\Fiber $fiber = null;
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
        if ($this->executed) {
            return false;
        }
        return $this->fiber !== null && !$this->fiber->isTerminated();
    }

    public function isCompleted(): bool
    {
        return $this->executed;
    }

    public function getResult(): mixed
    {
        // Drive to completion cooperatively, handling SleepOp when not scheduled externally.
        if ($this->executed) {
            if ($this->error !== null) {
                throw $this->error;
            }
            return $this->result;
        }

        $taskName = $this->name ?? $this->inferName();
        $started = false;
        try {
            /** @phpstan-ignore-next-line */
            while (!$this->executed) {
                if ($this->fiber === null) {
                    $this->metrics->taskStarted($taskName);
                    $started = true;
                    $fn = $this->closure;
                    $this->fiber = new \Fiber(static function () use ($fn) {
                        return $fn();
                    });
                    $suspend = $this->fiber->start();
                } else {
                    $suspend = $this->fiber->resume(null);
                }

                if ($this->fiber->isTerminated()) {
                    $this->result = $this->fiber->getReturn();
                    $this->executed = true;
                    break;
                }

                if ($suspend instanceof SleepOp) {
                    if ($suspend->token !== null && $suspend->token->isCancelled()) {
                        $suspend->token->throwIfCancelled();
                    }
                    $now = microtime(true);
                    $timeout = max(0.0, $suspend->wakeAt - $now);
                    if ($timeout > 0) {
                        usleep((int) ($timeout * 1_000_000));
                    }
                    continue;
                }
                // Unknown suspend value: continue immediately
            }
            if ($started) {
                $this->metrics->taskCompleted($taskName);
            }
        } catch (\Throwable $e) {
            $this->error = $e;
            if ($started) {
                $this->metrics->taskFailed($taskName, $e);
            }
            $this->executed = true;
        }

        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->result;
    }

    /** Execute one step and return suspend value or null when done. */
    public function step(): mixed
    {
        if ($this->executed) {
            return null;
        }
        try {
            if ($this->fiber === null) {
                $this->metrics->taskStarted($this->name ?? $this->inferName());
                $fn = $this->closure;
                $this->fiber = new \Fiber(static function () use ($fn) {
                    return $fn();
                });
                $suspend = $this->fiber->start();
            } else {
                $suspend = $this->fiber->resume(null);
            }
            if ($this->fiber->isTerminated()) {
                $this->result = $this->fiber->getReturn();
                $this->metrics->taskCompleted($this->name ?? $this->inferName());
                $this->executed = true;
                return null;
            }
            return $suspend;
        } catch (\Throwable $e) {
            $this->error = $e;
            $this->metrics->taskFailed($this->name ?? $this->inferName(), $e);
            $this->executed = true;
            return null;
        }
    }

    public function cancel(): void
    {
        // No cooperative cancellation mechanism wired yet
    }

    private function inferName(): string
    {
        $ref = new \ReflectionFunction($this->closure);
        if ($ref->isClosure()) {
            $fileName = $ref->getFileName();
            $file = basename($fileName !== false ? $fileName : 'closure');
            $line = $ref->getStartLine();
            return 'fiber@' . $file . ':' . $line;
        }
        return 'fiber-task';
    }
}
