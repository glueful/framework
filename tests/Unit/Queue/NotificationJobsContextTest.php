<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue;

use Glueful\Notifications\Exceptions\NotificationContextRequiredException;
use Glueful\Queue\Jobs\DispatchNotificationChannels;
use Glueful\Queue\Jobs\SendNotification;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 / Task 5c: notification jobs require an ApplicationContext to resolve the shared
 * dispatcher. Constructed without one, their service resolution throws rather than building
 * ad-hoc managers.
 */
final class NotificationJobsContextTest extends TestCase
{
    public function testDispatchNotificationChannelsRequiresContext(): void
    {
        $job = new DispatchNotificationChannels([], null);
        $method = new \ReflectionMethod($job, 'resolveNotificationService');
        $method->setAccessible(true);

        $this->expectException(NotificationContextRequiredException::class);
        $method->invoke($job);
    }

    public function testSendNotificationRequiresContext(): void
    {
        $job = new SendNotification([], null);
        $method = new \ReflectionMethod($job, 'getNotificationService');
        $method->setAccessible(true);

        $this->expectException(NotificationContextRequiredException::class);
        $method->invoke($job);
    }
}
