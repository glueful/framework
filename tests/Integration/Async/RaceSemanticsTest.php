<?php

declare(strict_types=1);

use Glueful\Async\FiberScheduler;
use PHPUnit\Framework\TestCase;

final class RaceSemanticsTest extends TestCase
{
    public function testFastestTaskWins(): void
    {
        $sch = new FiberScheduler();
        $slow = $sch->spawn(function () use ($sch) {
            $sch->sleep(0.1);
            return 'slow';
        });
        $fast = $sch->spawn(function () {
            return 'fast';
        });

        $winner = $sch->race([$slow, $fast]);
        $this->assertSame('fast', $winner);
    }
}

