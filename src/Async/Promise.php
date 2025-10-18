<?php

declare(strict_types=1);

namespace Glueful\Async;

use Glueful\Async\Contracts\Task;
use Glueful\Async\Task\CompletedTask;
use Glueful\Async\Task\FailedTask;
use Glueful\Async\Task\FiberTask;

/**
 * Lightweight Promise-style wrapper around async Tasks for ergonomic chaining.
 *
 * This wrapper does NOT change the underlying Task execution model; it simply
 * provides familiar then/catch/finally composition helpers that return new
 * Promise instances. Each chaining method constructs a FiberTask that awaits
 * the previous task and applies the provided callbacks.
 *
 * Interop rules for callback return values:
 * - If a callback returns a Promise, its underlying Task is awaited.
 * - If a callback returns a Task, that Task is awaited.
 * - Otherwise, the raw value is returned.
 */
final class Promise
{
    public function __construct(private Task $task)
    {
    }

    /**
     * Get the underlying Task.
     */
    public function task(): Task
    {
        return $this->task;
    }

    /**
     * Await the underlying Task and return its result (or throw on failure).
     */
    public function await(): mixed
    {
        return $this->task->getResult();
    }

    /**
     * Chain a success callback. The resulting Promise resolves to the callback's return value.
     *
     * @param callable(mixed): (mixed|Task|self) $onFulfilled
     */
    public function then(callable $onFulfilled): self
    {
        $parent = $this->task;
        $next = new FiberTask(static function () use ($parent, $onFulfilled) {
            $value = $parent->getResult();
            $res = $onFulfilled($value);
            return Promise::unwrap($res);
        });
        return new self($next);
    }

    /**
     * Chain an error handler. The resulting Promise resolves to either the original
     * value (when no error) or the handler's return value when an error occurs.
     *
     * @param callable(\Throwable): (mixed|Task|self) $onRejected
     */
    public function catch(callable $onRejected): self
    {
        $parent = $this->task;
        $next = new FiberTask(static function () use ($parent, $onRejected) {
            try {
                return $parent->getResult();
            } catch (\Throwable $e) {
                $res = $onRejected($e);
                return Promise::unwrap($res);
            }
        });
        return new self($next);
    }

    /**
     * Chain a finally-like callback. The callback runs regardless of outcome.
     * The original result or error is rethrown after the callback completes.
     *
     * @param callable(): (void|Task|self) $onFinally
     */
    public function finally(callable $onFinally): self
    {
        $parent = $this->task;
        $next = new FiberTask(static function () use ($parent, $onFinally) {
            try {
                $result = $parent->getResult();
            } catch (\Throwable $e) {
                // Run finally, then rethrow
                Promise::unwrap($onFinally());
                throw $e;
            }
            // Success path: run finally, then return value
            Promise::unwrap($onFinally());
            return $result;
        });
        return new self($next);
    }

    /**
     * Create a resolved Promise from a value.
     */
    public static function resolve(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if ($value instanceof Task) {
            return new self($value);
        }
        return new self(new CompletedTask($value));
    }

    /**
     * Create a rejected Promise from an exception.
     */
    public static function reject(\Throwable $reason): self
    {
        return new self(new FailedTask($reason));
    }

    /**
     * Convert a Task to a Promise.
     */
    public static function fromTask(Task $task): self
    {
        return new self($task);
    }

    /**
     * Await all given Promises/Tasks and resolve to an array of results (preserves keys).
     *
     * @param array<int|string, Promise|Task> $items
     */
    public static function all(array $items): self
    {
        $tasks = [];
        foreach ($items as $k => $v) {
            $tasks[$k] = $v instanceof self ? $v->task() : ($v instanceof Task ? $v : new CompletedTask($v));
        }
        $task = new FiberTask(static function () use ($tasks) {
            // Use helper to obtain a scheduler (DI/container if available, else fallback)
            return \scheduler()->all($tasks);
        });
        return new self($task);
    }

    /**
     * Resolve to the first succeeded Promise/Task (rethrows if all fail).
     *
     * @param array<int|string, Promise|Task> $items
     */
    public static function race(array $items): self
    {
        $tasks = [];
        foreach ($items as $k => $v) {
            $tasks[$k] = $v instanceof self ? $v->task() : ($v instanceof Task ? $v : new CompletedTask($v));
        }
        $task = new FiberTask(static function () use ($tasks) {
            return \scheduler()->race($tasks);
        });
        return new self($task);
    }

    /**
     * Helper to unwrap callback returns into a concrete value.
     * If a Promise or Task is returned, await it; otherwise return the value.
     */
    private static function unwrap(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->task()->getResult();
        }
        if ($value instanceof Task) {
            return $value->getResult();
        }
        return $value;
    }
}
