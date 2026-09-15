<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Notifications\Exceptions\NotificationContextRequiredException;
use Glueful\Queue\JobHandlerResolver;
use Glueful\Queue\Jobs\CacheMaintenanceJob;
use Glueful\Queue\Jobs\DatabaseBackupJob;
use Glueful\Queue\Jobs\DispatchNotificationChannels;
use Glueful\Queue\Jobs\LogCleanupJob;
use Glueful\Queue\Jobs\NotificationRetryJob;
use Glueful\Queue\Jobs\SendNotification;
use Glueful\Queue\Jobs\SessionCleanupJob;
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

    /**
     * The scheduler resolves a config-declared job through JobHandlerResolver, which hands the
     * application context to the base constructor. A job overriding that constructor without
     * the context parameter dropped it — and NotificationRetryJob then threw on every due tick,
     * because its task refuses to run context-less.
     *
     * @return iterable<string, array{class-string}>
     */
    public static function scheduledJobs(): iterable
    {
        yield 'notification retry' => [NotificationRetryJob::class];
        yield 'session cleanup' => [SessionCleanupJob::class];
        yield 'log cleanup' => [LogCleanupJob::class];
        yield 'cache maintenance' => [CacheMaintenanceJob::class];
        yield 'database backup' => [DatabaseBackupJob::class];
    }

    /**
     * @dataProvider scheduledJobs
     * @param class-string $class
     */
    public function testAScheduledJobKeepsTheContextTheResolverHandsIt(string $class): void
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/jobs_context_' . uniqid());
        $job = JobHandlerResolver::resolve($class, ['limit' => 1], $context);
        $property = new \ReflectionProperty($job, 'context');
        $property->setAccessible(true);
        self::assertSame($context, $property->getValue($job), $class);
    }
}

