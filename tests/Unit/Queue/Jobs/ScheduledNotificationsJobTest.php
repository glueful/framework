<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue\Jobs;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Notifications\Contracts\NotificationStoreInterface;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Notifications\Stores\NullNotificationStore;
use Glueful\Queue\JobHandlerResolver;
use Glueful\Queue\Jobs\ScheduledNotificationsJob;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * A notification sent with `schedule` is stored for later, but nothing ever called
 * processScheduledNotifications(): scheduled notifications were never delivered. The built-in
 * `scheduled_notifications` job now processes the due ones, and is a no-op when notification
 * persistence is off (NOTIFICATIONS_DATABASE_STORE=false), where nothing can be scheduled.
 */
final class ScheduledNotificationsJobTest extends TestCase
{
    /**
     * @param array<string, mixed> $services
     */
    private function context(array $services): ApplicationContext
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/sched_notif_' . uniqid());
        $context->setContainer(new class ($services) implements ContainerInterface {
            /** @param array<string, mixed> $services */
            public function __construct(private array $services)
            {
            }

            public function get(string $id): mixed
            {
                if (!array_key_exists($id, $this->services)) {
                    throw new \RuntimeException("Service '{$id}' not found");
                }
                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
        });

        return $context;
    }

    public function testDueScheduledNotificationsAreProcessedWithTheConfiguredLimit(): void
    {
        $store = $this->createMock(NotificationStoreInterface::class);
        $store->method('isPersistent')->willReturn(true);
        $service = $this->createMock(NotificationService::class);
        $service->expects(self::once())
            ->method('processScheduledNotifications')
            ->with(25)
            ->willReturn(['processed' => 1, 'successful' => 1, 'failed' => 0]);

        $context = $this->context([
            NotificationStoreInterface::class => $store,
            NotificationService::class => $service,
        ]);
        JobHandlerResolver::resolve(ScheduledNotificationsJob::class, ['options' => ['limit' => 25]], $context)
            ->handle();
    }

    public function testWithoutNotificationPersistenceTheJobDoesNothing(): void
    {
        $service = $this->createMock(NotificationService::class);
        $service->expects(self::never())->method('processScheduledNotifications');

        $context = $this->context([
            NotificationStoreInterface::class => new NullNotificationStore(),
            NotificationService::class => $service,
        ]);
        JobHandlerResolver::resolve(ScheduledNotificationsJob::class, [], $context)->handle();
    }

    public function testTheDefaultScheduleRunsTheJob(): void
    {
        $config = require dirname(__DIR__, 4) . '/config/schedule.php';
        $jobs = array_column($config['jobs'], null, 'name');

        self::assertArrayHasKey('scheduled_notifications', $jobs);
        self::assertSame(ScheduledNotificationsJob::class, $jobs['scheduled_notifications']['handler_class']);
        self::assertSame('* * * * *', $jobs['scheduled_notifications']['schedule']);
    }
}
