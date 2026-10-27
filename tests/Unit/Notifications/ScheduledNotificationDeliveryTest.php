<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Notifications;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Models\Notification;
use Glueful\Notifications\Services\NotificationDispatcher;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Repository\NotificationRepository;
use PHPUnit\Framework\TestCase;

/**
 * A scheduled notification used to lose its requested channels: processScheduledNotifications()
 * dispatched with the default channels, and the rebuilt user recipient routed nothing for
 * 'email', so channels=['email'] became a database-only delivery (email → no_recipient).
 */
final class ScheduledNotificationDeliveryTest extends TestCase
{
    /** @var list<Notification> */
    private array $saved = [];

    /** @var list<array{channels: array<string>|null, email: mixed}> */
    private array $dispatches = [];

    private function service(): NotificationService
    {
        $repository = $this->createMock(NotificationRepository::class);
        $repository->method('isPersistent')->willReturn(true);
        $repository->method('save')->willReturnCallback(function (Notification $n): bool {
            $this->saved[] = $n;
            return true;
        });
        $repository->method('getChannelsNeedingDispatch')->willReturnCallback(
            static fn(string $uuid, array $requested): array => $requested
        );
        $repository->method('findPendingScheduled')->willReturnCallback(
            fn(): array => array_values(array_filter($this->saved, static fn(Notification $n) => !$n->isSent()))
        );

        $dispatcher = $this->createMock(NotificationDispatcher::class);
        $dispatcher->method('send')->willReturnCallback(
            function (Notification $n, Notifiable $to, ?array $channels = null): array {
                $this->dispatches[] = ['channels' => $channels, 'email' => $to->routeNotificationFor('email')];
                $results = [];
                foreach ($channels ?? ['database'] as $channel) {
                    $results[$channel] = ['status' => 'success'];
                }
                return ['status' => 'success', 'channels' => $results];
            }
        );

        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('findByUuid')->willReturnCallback(
            static fn(string $uuid) => $uuid === 'user-1'
                ? new UserIdentity('user-1', email: 'ada@example.com')
                : null
        );
        $context = new ApplicationContext(sys_get_temp_dir() . '/scheduled_notifications_' . uniqid());
        $context->setContainer(new Container([UserProviderInterface::class => $provider]));

        return new NotificationService($dispatcher, $repository, null, null, [], $context);
    }

    private function recipient(): Notifiable
    {
        return new class implements Notifiable {
            public function routeNotificationFor(string $channel)
            {
                return $channel === 'email' ? 'ada@example.com' : null;
            }

            public function getNotifiableId(): string
            {
                return 'user-1';
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

    public function testScheduledNotificationIsDeliveredOnItsRequestedChannels(): void
    {
        $service = $this->service();
        $result = $service->send('welcome', $this->recipient(), 'Hello', [], [
            'channels' => ['email'],
            'schedule' => new \DateTime('-1 minute'),
        ]);
        self::assertSame('scheduled', $result['status']);

        $stats = $service->processScheduledNotifications();

        self::assertSame(['processed' => 1, 'successful' => 1, 'failed' => 0], $stats);
        self::assertCount(1, $this->dispatches);
        self::assertSame(['email'], $this->dispatches[0]['channels']);
    }

    public function testRebuiltUserRecipientRoutesEmailToTheUsersAddress(): void
    {
        $service = $this->service();
        $service->send('welcome', $this->recipient(), 'Hello', [], [
            'channels' => ['email'],
            'schedule' => new \DateTime('-1 minute'),
        ]);

        $service->processScheduledNotifications();

        self::assertSame('ada@example.com', $this->dispatches[0]['email']);
    }

    public function testScheduledNotificationWithoutRequestedChannelsUsesTheDefaults(): void
    {
        $service = $this->service();
        $service->send('welcome', $this->recipient(), 'Hello', [], ['schedule' => new \DateTime('-1 minute')]);

        $service->processScheduledNotifications();

        self::assertSame(['database'], $this->dispatches[0]['channels']);
    }
}
