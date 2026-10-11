<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue;

use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Queue\RestartSignal;
use PHPUnit\Framework\TestCase;

/**
 * queue:restart's signal: a timestamp in the cache that running workers compare with their own start.
 */
final class QueueRestartTest extends TestCase
{
    public function testASignalAfterAWorkerStartedAsksItToRestart(): void
    {
        $signal = new RestartSignal(new ArrayCacheDriver());
        $signal->signal(1000);
        self::assertTrue($signal->requestedAfter(1000));
        self::assertTrue($signal->requestedAfter(999));
        self::assertFalse($signal->requestedAfter(1001), 'a worker started after the signal keeps running');
    }

    public function testNoSignalNoRestart(): void
    {
        self::assertFalse((new RestartSignal(new ArrayCacheDriver()))->requestedAfter(1));
    }

    public function testWithoutACacheNothingRestarts(): void
    {
        $signal = new RestartSignal(null);
        $signal->signal(5);
        self::assertFalse($signal->requestedAfter(1));
    }
}
