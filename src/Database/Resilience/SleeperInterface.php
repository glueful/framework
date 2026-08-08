<?php

declare(strict_types=1);

namespace Glueful\Database\Resilience;

/**
 * Clock seam for retry backoff. Production wraps usleep(); tests record
 * requested delays instead of actually waiting.
 */
interface SleeperInterface
{
    public function sleepMilliseconds(int $milliseconds): void;
}
