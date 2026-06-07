<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue;

use Glueful\Queue\WorkerOptions;
use PHPUnit\Framework\TestCase;

/**
 * WS2 Task 2b: maxJobs / maxRuntime accept 0 as "unlimited".
 *
 * The constructor must floor these two fields at 0 (not 1 / 60) so a worker
 * can run with no job cap and no runtime cap, while validate()/isValid()
 * still treat the zero case as valid. Every other clamp stays untouched.
 */
final class WorkerOptionsZeroUnlimitedTest extends TestCase
{
    public function testZeroMaxJobsAndMaxRuntimeAreNotClampedUp(): void
    {
        $options = new WorkerOptions(maxJobs: 0, maxRuntime: 0);

        $this->assertSame(0, $options->maxJobs);
        $this->assertSame(0, $options->maxRuntime);
    }

    public function testZeroMaxJobsAndMaxRuntimeAreValid(): void
    {
        $options = new WorkerOptions(maxJobs: 0, maxRuntime: 0);

        $this->assertTrue($options->isValid());
        $this->assertSame([], $options->validate());
    }

    public function testNegativeMaxJobsAndMaxRuntimeFloorAtZero(): void
    {
        $options = new WorkerOptions(maxJobs: -5, maxRuntime: -120);

        $this->assertSame(0, $options->maxJobs);
        $this->assertSame(0, $options->maxRuntime);
        $this->assertTrue($options->isValid());
    }

    public function testOtherClampsStillEnforceMinimums(): void
    {
        // memory floors at 32, sleep floors at 1 — proves the change was surgical.
        $options = new WorkerOptions(sleep: 0, memory: 1);

        $this->assertSame(1, $options->sleep);
        $this->assertSame(32, $options->memory);
    }
}
