<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Notifications;

use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationChannel;
use Glueful\Notifications\Contracts\RichNotificationChannel;
use Glueful\Notifications\Models\Notification;
use Glueful\Notifications\Results\NotificationResult;
use Glueful\Notifications\Services\ChannelManager;
use Glueful\Notifications\Services\NotificationDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4: the dispatcher normalizes both legacy `send(): bool` channels and rich channels
 * returning a NotificationResult into one per-channel result shape.
 */
final class NotificationResultNormalizationTest extends TestCase
{
    /** @return array<string, mixed> */
    private function dispatch(NotificationChannel $channel): array
    {
        $manager = new ChannelManager();
        $manager->registerChannel($channel);
        $dispatcher = new NotificationDispatcher($manager, null, [], null);
        $notification = new Notification('test_type', 'Subject', 'user', 'id-1', ['body' => 'hi'], 'u_1', '1');

        $result = $dispatcher->send($notification, $this->notifiable(), [$channel->getChannelName()]);

        return $result['channels'][$channel->getChannelName()];
    }

    public function testLegacyBoolTrueChannelNormalizesToSuccess(): void
    {
        $channel = $this->legacyChannel('legacy_ok', true);
        self::assertSame('success', $this->dispatch($channel)['status']);
    }

    public function testLegacyBoolFalseChannelNormalizesToFailed(): void
    {
        $channelResult = $this->dispatch($this->legacyChannel('legacy_fail', false));
        self::assertSame('failed', $channelResult['status']);
        self::assertSame('send_failed', $channelResult['reason']);
    }

    public function testRichChannelSuccessSurfacesProviderMessageId(): void
    {
        $channel = $this->richChannel('rich_ok', NotificationResult::success(providerMessageId: 'pm_123'));
        $channelResult = $this->dispatch($channel);
        self::assertSame('success', $channelResult['status']);
        self::assertSame('pm_123', $channelResult['provider_message_id']);
    }

    public function testRichChannelFailureSurfacesErrorCodeAndMessage(): void
    {
        $channel = $this->richChannel(
            'rich_fail',
            NotificationResult::failure(errorCode: 'rate_limited', errorMessage: 'slow down')
        );
        $channelResult = $this->dispatch($channel);
        self::assertSame('failed', $channelResult['status']);
        self::assertSame('rate_limited', $channelResult['reason']);
        self::assertSame('slow down', $channelResult['message']);
    }

    private function legacyChannel(string $name, bool $ok): NotificationChannel
    {
        return new class ($name, $ok) implements NotificationChannel {
            public function __construct(private string $name, private bool $ok)
            {
            }

            public function getChannelName(): string
            {
                return $this->name;
            }

            public function send(Notifiable $notifiable, array $data): bool
            {
                return $this->ok;
            }

            public function format(array $data, Notifiable $notifiable): array
            {
                return $data;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getConfig(): array
            {
                return [];
            }
        };
    }

    private function richChannel(string $name, NotificationResult $result): RichNotificationChannel
    {
        return new class ($name, $result) implements RichNotificationChannel {
            public function __construct(private string $name, private NotificationResult $result)
            {
            }

            public function getChannelName(): string
            {
                return $this->name;
            }

            public function sendNotification(Notifiable $notifiable, array $data): NotificationResult
            {
                return $this->result;
            }

            public function send(Notifiable $notifiable, array $data): bool
            {
                return $this->result->success; // not used by the dispatcher for rich channels
            }

            public function format(array $data, Notifiable $notifiable): array
            {
                return $data;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getConfig(): array
            {
                return [];
            }
        };
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
