<?php

declare(strict_types=1);

use Glueful\Async\FiberScheduler;
use Glueful\Async\SimpleCancellationToken;
use PHPUnit\Framework\TestCase;

final class SchedulerTimerCancellationTest extends TestCase
{
    public function testCancelledSleepWakesPromptlyAndThrows(): void
    {
        $scheduler = new FiberScheduler();
        $token = new SimpleCancellationToken();

        $task = $scheduler->spawn(function () use ($scheduler, $token) {
            // Sleep for a long time but will be cancelled
            $scheduler->sleep(5.0, $token);
            return 'never';
        }, $token);

        // Cancel immediately
        $token->cancel();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Operation cancelled');
        $scheduler->all([$task]);
    }
}

