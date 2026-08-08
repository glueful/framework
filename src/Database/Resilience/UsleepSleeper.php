<?php

declare(strict_types=1);

namespace Glueful\Database\Resilience;

final class UsleepSleeper implements SleeperInterface
{
    public function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
