<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Notifications;

use Glueful\Notifications\Channels\DatabaseChannel;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Models\Notification;
use Glueful\Notifications\Services\ChannelManager;
use Glueful\Notifications\Services\NotificationDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1: end-to-end dispatch outcomes for the core `database` channel — proving the real
 * behavioral claims (not just structural ones): a registered+available channel succeeds, an
 * unregistered channel is `channel_not_found`, and a persistence-off database channel is
 * `channel_unavailable` (never success).
 */
final class DatabaseChannelDispatchTest extends TestCase
{
    /**
     * @param array<string> $channels
     * @return array<string, mixed>
     */
    private function dispatch(ChannelManager $manager, array $channels): array
    {
        $dispatcher = new NotificationDispatcher($manager, null, [], null);
        $notification = new Notification('test_type', 'Subject', 'user', 'id-1', ['body' => 'hi'], 'u_1', '1');

        return $dispatcher->send($notification, $this->notifiable(), $channels);
    }

    public function testRegisteredAvailableDatabaseChannelSucceeds(): void
    {
        $manager = new ChannelManager();
        $manager->registerChannel(new DatabaseChannel(persistenceEnabled: true));

        $result = $this->dispatch($manager, ['database']);

        self::assertSame('success', $result['channels']['database']['status']);
    }

    public function testUnregisteredChannelIsChannelNotFound(): void
    {
        $manager = new ChannelManager();
        $manager->registerChannel(new DatabaseChannel());

        $result = $this->dispatch($manager, ['ghost']);

        self::assertSame('channel_not_found', $result['channels']['ghost']['reason']);
    }

    public function testDatabaseChannelIsUnavailableWhenPersistenceOff(): void
    {
        $manager = new ChannelManager();
        $manager->registerChannel(new DatabaseChannel(persistenceEnabled: false));

        $result = $this->dispatch($manager, ['database']);

        self::assertSame('channel_unavailable', $result['channels']['database']['reason']);
        self::assertNotSame('success', $result['channels']['database']['status']);
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
