<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Notifications;

use DateTime;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Exceptions\NotificationPersistenceDisabledException;
use Glueful\Notifications\Models\Notification;
use Glueful\Notifications\Services\NotificationDispatcher;
use Glueful\Notifications\Services\NotificationRetryService;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Notifications\Stores\NullNotificationStore;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2: durability-implying flows fail loudly when persistence is disabled, instead of
 * silently dropping work (scheduled notifications) or hitting a gated table (retries).
 */
final class NotificationNoDbGuardsTest extends TestCase
{
    private function service(): NotificationService
    {
        return new NotificationService(
            $this->createMock(NotificationDispatcher::class),
            new NullNotificationStore(),
            null,
            null,
            []
        );
    }

    public function testSchedulingThrowsWhenPersistenceDisabled(): void
    {
        $this->expectException(NotificationPersistenceDisabledException::class);
        $this->service()->send(
            'test_type',
            $this->notifiable(),
            'Subject',
            [],
            ['schedule' => new DateTime('+1 hour')]
        );
    }

    public function testDispatchStoredNotificationThrowsWhenPersistenceDisabled(): void
    {
        $this->expectException(NotificationPersistenceDisabledException::class);
        $this->service()->dispatchStoredNotification('u_1');
    }

    public function testProcessScheduledNotificationsThrowsWhenPersistenceDisabled(): void
    {
        $this->expectException(NotificationPersistenceDisabledException::class);
        $this->service()->processScheduledNotifications();
    }

    public function testRetryQueueThrowsWhenPersistenceDisabled(): void
    {
        $retry = new NotificationRetryService(null, new NullNotificationStore());
        $this->expectException(NotificationPersistenceDisabledException::class);
        $retry->queueForRetry($this->notification(), $this->notifiable(), 'database');
    }

    public function testProcessDueRetriesThrowsWhenPersistenceDisabled(): void
    {
        $retry = new NotificationRetryService(null, new NullNotificationStore());
        $this->expectException(NotificationPersistenceDisabledException::class);
        $retry->processDueRetries(10, $this->createMock(NotificationService::class));
    }

    private function notification(): Notification
    {
        return new Notification('test_type', 'Subject', 'user', 'id-1', ['body' => 'hi'], 'u_1', '1');
    }

    private function notifiable(): Notifiable
    {
        return new class implements Notifiable {
            public function routeNotificationFor(string $channel)
            {
                return 'route';
            }

            public function getNotifiableId(): string
            {
                return 'id-1';
            }

            public function getNotifiableType(): string
            {
                return 'user';
            }

            public function shouldReceiveNotification(string $notificationType, string $channel): bool
            {
                return true;
            }

            public function getNotificationPreferences(): array
            {
                return [];
            }
        };
    }
}
