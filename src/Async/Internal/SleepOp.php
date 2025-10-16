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
 * 1. Scheduler::sleep() suspends with: Fiber::suspend(new SleepOp($wakeAt))
 * 2. FiberScheduler receives the SleepOp in task->step()
 * 3. Scheduler adds task to timers queue sorted by wake time
 * 4. Event loop sleeps until earliest timer or I/O readiness
 * 5. When time arrives, scheduler resumes the fiber
 * 6. Execution continues after the sleep
 *
 * Example (internal):
 * ```php
 * // Inside FiberScheduler::sleep()
 * $wakeAt = microtime(true) + $seconds;
 * Fiber::suspend(new SleepOp($wakeAt, $token));
 * ```
 *
 * @internal This class is part of the async timing implementation
 */
final class SleepOp
{
    /**
     * @var float Absolute timestamp when the fiber should wake up
     *            (in microtime(true) format)
     */
    public float $wakeAt;

    /**
     * @var CancellationToken|null Optional cancellation token for the sleep
     */
    public ?CancellationToken $token;

    /**
     * Creates a sleep operation suspension marker.
     *
     * @param float $wakeAt Absolute timestamp to wake at (microtime(true))
     * @param CancellationToken|null $token Optional cancellation token
     */
    public function __construct(float $wakeAt, ?CancellationToken $token = null)
    {
        $this->wakeAt = $wakeAt;
        $this->token = $token;
    }
}
