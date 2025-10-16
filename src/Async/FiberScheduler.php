<?php

declare(strict_types=1);

namespace Glueful\Async;

use Glueful\Async\Contracts\Scheduler;
use Glueful\Async\Contracts\Task;
use Glueful\Async\Contracts\CancellationToken;
use Glueful\Async\Task\FiberTask;
use Glueful\Async\Instrumentation\Metrics;
use Glueful\Async\Instrumentation\NullMetrics;

final class FiberScheduler implements Scheduler
{
    public function __construct(private ?Metrics $metrics = null)
    {
        $this->metrics = $this->metrics ?? new NullMetrics();
    }

    public function spawn(callable $fn, ?CancellationToken $token = null): Task
    {
        return new FiberTask($fn, $this->metrics);
    }

    public function all(array $tasks): array
    {
        $ready = new \SplQueue();
        $results = [];
        $pending = [];
        foreach ($tasks as $k => $t) {
            if ($t instanceof \Glueful\Async\Task\FiberTask) {
                $pending[$k] = $t;
                $ready->enqueue([$k, $t]);
            } else {
                $results[$k] = $t->getResult();
            }
        }

        $timers = [];
        $readWaiters = [];
        $writeWaiters = [];
        while ($pending !== []) {
            if (!$ready->isEmpty()) {
                [$k, $task] = $ready->dequeue();
                $suspend = $task->step();
                if ($task->isCompleted()) {
                    $results[$k] = $task->getResult();
                    unset($pending[$k]);
                    continue;
                }
                if ($suspend instanceof \Glueful\Async\Internal\SleepOp) {
                    $timers[] = [$suspend->wakeAt, $k, $task, $suspend->token];
                } elseif ($suspend instanceof \Glueful\Async\Internal\ReadOp) {
                    $readWaiters[] = [$suspend->stream, $k, $task, $suspend->deadline, $suspend->token];
                } elseif ($suspend instanceof \Glueful\Async\Internal\WriteOp) {
                    $writeWaiters[] = [$suspend->stream, $k, $task, $suspend->deadline, $suspend->token];
                } else {
                    $ready->enqueue([$k, $task]);
                }
            } else {
                // Idle: wait for earliest of IO readiness or timer
                $nextAt = null;
                if ($timers !== []) {
                    usort($timers, static fn($a, $b) => $a[0] <=> $b[0]);
                    $nextAt = $timers[0][0];
                }
                foreach ($readWaiters as $rw) {
                    $d = $rw[3];
                    if ($d !== null) {
                        $nextAt = $nextAt === null ? $d : min($nextAt, $d);
                    }
                }
                foreach ($writeWaiters as $ww) {
                    $d = $ww[3];
                    if ($d !== null) {
                        $nextAt = $nextAt === null ? $d : min($nextAt, $d);
                    }
                }

                $timeoutSec = null;
                $timeoutUsec = null;
                if ($nextAt !== null) {
                    $now = microtime(true);
                    $delta = max(0.0, $nextAt - $now);
                    $timeoutSec = (int) floor($delta);
                    $timeoutUsec = (int) (($delta - $timeoutSec) * 1_000_000);
                }

                $r = array_map(fn($e) => $e[0], $readWaiters);
                $w = array_map(fn($e) => $e[0], $writeWaiters);
                $e = null;

                if ($r === [] && $w === []) {
                    if ($nextAt === null) {
                        break;
                    }
                    $sleepMicros = (($timeoutSec ?? 0) * 1_000_000) + ($timeoutUsec ?? 0);
                    if ($sleepMicros > 0) {
                        usleep($sleepMicros);
                    }
                } else {
                    @stream_select($r, $w, $e, $timeoutSec, $timeoutUsec);
                }

                $now = microtime(true);
                // Requeue ready read tasks or timed out ones
                $newReadWaiters = [];
                foreach ($readWaiters as $entry) {
                    [$stream, $k2, $t2, $deadline, $tok] = $entry;
                    $isReady = in_array($stream, $r, true);
                    $isTimeout = ($deadline !== null && $now >= $deadline) || ($tok !== null && $tok->isCancelled());
                    if ($tok !== null && $tok->isCancelled()) {
                        try {
                            $tok->throwIfCancelled();
                        } catch (\Throwable $ex) {
                            // ignore
                        }
                    }
                    if ($isReady || $isTimeout) {
                        $ready->enqueue([$k2, $t2]);
                    } else {
                        $newReadWaiters[] = $entry;
                    }
                }
                $readWaiters = $newReadWaiters;

                // Requeue ready write tasks or timed out ones
                $newWriteWaiters = [];
                foreach ($writeWaiters as $entry) {
                    [$stream, $k2, $t2, $deadline, $tok] = $entry;
                    $isReady = in_array($stream, $w, true);
                    $isTimeout = ($deadline !== null && $now >= $deadline) || ($tok !== null && $tok->isCancelled());
                    if ($tok !== null && $tok->isCancelled()) {
                        try {
                            $tok->throwIfCancelled();
                        } catch (\Throwable $ex) {
                            // ignore
                        }
                    }
                    if ($isReady || $isTimeout) {
                        $ready->enqueue([$k2, $t2]);
                    } else {
                        $newWriteWaiters[] = $entry;
                    }
                }
                $writeWaiters = $newWriteWaiters;

                // Timers due
                $newTimers = [];
                foreach ($timers as $tm) {
                    [$wakeAt, $k3, $t3, $tok3] = $tm;
                    if ($now >= $wakeAt) {
                        $ready->enqueue([$k3, $t3]);
                    } else {
                        $newTimers[] = $tm;
                    }
                }
                $timers = $newTimers;
            }
        }

        return $results;
    }

    public function race(array $tasks): mixed
    {
        $ready = new \SplQueue();
        $pending = [];
        $timers = [];
        $readWaiters = [];
        $writeWaiters = [];
        $firstError = null;

        foreach ($tasks as $k => $t) {
            if ($t instanceof \Glueful\Async\Task\FiberTask) {
                $pending[$k] = $t;
                $ready->enqueue([$k, $t]);
            } else {
                try {
                    return $t->getResult();
                } catch (\Throwable $e) {
                    $firstError ??= $e;
                }
            }
        }

        while ($pending !== []) {
            if (!$ready->isEmpty()) {
                [$k, $task] = $ready->dequeue();
                try {
                    $suspend = $task->step();
                    if ($task->isCompleted()) {
                        return $task->getResult();
                    }
                    if ($suspend instanceof \Glueful\Async\Internal\SleepOp) {
                        $timers[] = [$suspend->wakeAt, $k, $task, $suspend->token];
                    } elseif ($suspend instanceof \Glueful\Async\Internal\ReadOp) {
                        $readWaiters[] = [$suspend->stream, $k, $task, $suspend->deadline, $suspend->token];
                    } elseif ($suspend instanceof \Glueful\Async\Internal\WriteOp) {
                        $writeWaiters[] = [$suspend->stream, $k, $task, $suspend->deadline, $suspend->token];
                    } else {
                        $ready->enqueue([$k, $task]);
                    }
                } catch (\Throwable $e) {
                    $firstError ??= $e;
                    unset($pending[$k]);
                }
            } else {
                // Idle: wait for earliest of IO readiness or timer
                $nextAt = null;
                if ($timers !== []) {
                    usort($timers, static fn($a, $b) => $a[0] <=> $b[0]);
                    $nextAt = $timers[0][0];
                }
                foreach ($readWaiters as $rw) {
                    $d = $rw[3];
                    if ($d !== null) {
                        $nextAt = $nextAt === null ? $d : min($nextAt, $d);
                    }
                }
                foreach ($writeWaiters as $ww) {
                    $d = $ww[3];
                    if ($d !== null) {
                        $nextAt = $nextAt === null ? $d : min($nextAt, $d);
                    }
                }

                $timeoutSec = null;
                $timeoutUsec = null;
                if ($nextAt !== null) {
                    $now = microtime(true);
                    $delta = max(0.0, $nextAt - $now);
                    $timeoutSec = (int) floor($delta);
                    $timeoutUsec = (int) (($delta - $timeoutSec) * 1_000_000);
                }

                $r = array_map(fn($e) => $e[0], $readWaiters);
                $w = array_map(fn($e) => $e[0], $writeWaiters);
                $e = null;

                if ($r === [] && $w === []) {
                    if ($nextAt === null) {
                        break;
                    }
                    $sleepMicros = (($timeoutSec ?? 0) * 1_000_000) + ($timeoutUsec ?? 0);
                    if ($sleepMicros > 0) {
                        usleep($sleepMicros);
                    }
                } else {
                    @stream_select($r, $w, $e, $timeoutSec, $timeoutUsec);
                }

                $now = microtime(true);
                // Requeue ready read tasks or timed out ones
                $newReadWaiters = [];
                foreach ($readWaiters as $entry) {
                    [$stream, $k2, $t2, $deadline, $tok] = $entry;
                    $isReady = in_array($stream, $r, true);
                    $isTimeout = ($deadline !== null && $now >= $deadline) || ($tok !== null && $tok->isCancelled());
                    if ($tok !== null && $tok->isCancelled()) {
                        try {
                            $tok->throwIfCancelled();
                        } catch (\Throwable $ex) {
                            // ignore
                        }
                    }
                    if ($isReady || $isTimeout) {
                        $ready->enqueue([$k2, $t2]);
                    } else {
                        $newReadWaiters[] = $entry;
                    }
                }
                $readWaiters = $newReadWaiters;

                // Requeue ready write tasks or timed out ones
                $newWriteWaiters = [];
                foreach ($writeWaiters as $entry) {
                    [$stream, $k2, $t2, $deadline, $tok] = $entry;
                    $isReady = in_array($stream, $w, true);
                    $isTimeout = ($deadline !== null && $now >= $deadline) || ($tok !== null && $tok->isCancelled());
                    if ($tok !== null && $tok->isCancelled()) {
                        try {
                            $tok->throwIfCancelled();
                        } catch (\Throwable $ex) {
                            // ignore
                        }
                    }
                    if ($isReady || $isTimeout) {
                        $ready->enqueue([$k2, $t2]);
                    } else {
                        $newWriteWaiters[] = $entry;
                    }
                }
                $writeWaiters = $newWriteWaiters;

                // Timers due
                $newTimers = [];
                foreach ($timers as $tm) {
                    [$wakeAt, $k3, $t3, $tok3] = $tm;
                    if ($now >= $wakeAt) {
                        $ready->enqueue([$k3, $t3]);
                    } else {
                        $newTimers[] = $tm;
                    }
                }
                $timers = $newTimers;
            }
        }

        if ($firstError !== null) {
            throw $firstError;
        }
        return null;
    }

    public function sleep(float $seconds, ?CancellationToken $token = null): void
    {
        if ($token !== null && $token->isCancelled()) {
            $token->throwIfCancelled();
        }
        $current = \Fiber::getCurrent();
        if ($current !== null) {
            $wakeAt = microtime(true) + max(0.0, $seconds);
            \Fiber::suspend(new \Glueful\Async\Internal\SleepOp($wakeAt, $token));
            return;
        }
        // Fallback when not inside a fiber
        usleep((int) max(0, $seconds * 1_000_000));
    }
}
