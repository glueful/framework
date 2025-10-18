<?php

declare(strict_types=1);

namespace Glueful\Async\Internal;

use Glueful\Async\Contracts\CancellationToken;

/**
 * Sleep operation suspension marker for async delays.
 *
 * SleepOp is an internal data object suspended by fibers to signal that they want
 * to sleep/delay until a specific time. The FiberScheduler intercepts this suspension,
 * adds the task to its timer queue, and resumes the fiber when the wake time arrives.
 *
 * This class is not meant to be instantiated directly by user code. It's created
 * internally by Scheduler::sleep() or similar async delay functions.
 *
 * Flow:
 * 1. User code calls: await(sleep(1.5)) or similar
 * 2. Scheduler::sleep() suspends with: Fiber::suspend(new SleepOp($wakeAt, $token))
 * 3. FiberScheduler receives the SleepOp in task->step()
 * 4. Scheduler adds task to timers queue sorted by wake time
 * 5. Event loop sleeps until earliest timer or I/O readiness
 * 6. When wake time arrives, scheduler resumes the fiber
 * 7. Execution continues after the sleep
 *
 * Key characteristics:
 * - **Cooperative**: Yields CPU to other tasks while sleeping
 * - **Precise timing**: Wake time is absolute, not relative (avoids drift)
 * - **Cancellable**: Can be cancelled via CancellationToken
 * - **Zero-cost when skipped**: If wake time already passed, resumes immediately
 * - **Non-blocking**: Never blocks the event loop or other tasks
 *
 * Cancellation behavior:
 * When a CancellationToken is provided and cancelled, the scheduler will:
 * 1. Remove the task from the timer queue
 * 2. Resume the fiber with a cancellation exception
 * 3. Allow the task to clean up resources
 *
 * Timing precision:
 * - Resolution depends on system clock (typically microseconds)
 * - Actual wake time may be slightly later due to scheduler overhead
 * - No guarantee of exact timing (use for delays, not hard real-time)
 *
 * Example (internal):
 * ```php
 * // Inside FiberScheduler::sleep()
 * $wakeAt = microtime(true) + $seconds;
 * Fiber::suspend(new SleepOp($wakeAt, $token));
 *
 * // With cancellation support
 * $token = new CancellationTokenSource();
 * Fiber::suspend(new SleepOp($wakeAt, $token->getToken()));
 * ```
 *
 * @internal This class is part of the async timing implementation
 */
final class SleepOp
{
    /**
     * Absolute timestamp when the fiber should wake up.
     *
     * This is an absolute time in microtime(true) format (Unix timestamp with
     * microsecond precision). Using absolute time instead of relative duration
     * prevents timing drift in scheduler loops.
     *
     * The scheduler compares this with microtime(true) to determine if the
     * task is ready to resume. If wakeAt <= current time, the task resumes
     * immediately without waiting.
     *
     * @var float Absolute timestamp in microtime(true) format
     */
    public float $wakeAt;

    /**
     * Optional cancellation token for the sleep operation.
     *
     * When provided, the scheduler checks this token before resuming the task.
     * If cancelled, the task is resumed with a cancellation exception instead
     * of continuing normally.
     *
     * This allows users to cancel long-running sleeps or timeouts externally
     * without waiting for the full duration.
     *
     * @var CancellationToken|null Cancellation token or null if not cancellable
     */
    public ?CancellationToken $token;

    /**
     * Creates a sleep operation suspension marker.
     *
     * @param float $wakeAt Absolute timestamp to wake at (microtime(true) format)
     * @param CancellationToken|null $token Optional cancellation token for early wake
     */
    public function __construct(float $wakeAt, ?CancellationToken $token = null)
    {
        $this->wakeAt = $wakeAt;
        $this->token = $token;
    }
}
