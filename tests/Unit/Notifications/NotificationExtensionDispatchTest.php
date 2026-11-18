<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Notifications;

use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationChannel;
use Glueful\Notifications\Contracts\NotificationExtension;
use Glueful\Notifications\Models\Notification;
use Glueful\Notifications\Services\ChannelManager;
use Glueful\Notifications\Services\NotificationDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * What a channel receives from the dispatcher: the notification's subject, and whatever the
 * extensions that handle the notification type added. `['*']` handles every type.
 */
final class NotificationExtensionDispatchTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $received = [];

    /**
     * @param list<NotificationExtension> $extensions
     * @param array<string, mixed> $data
     */
    private function dispatch(array $extensions, array $data = ['body' => 'hi'], string $type = 'follow'): void
    {
        $manager = new ChannelManager();
        $manager->registerChannel($this->recordingChannel());
        $dispatcher = new NotificationDispatcher($manager, null, [], null);
        foreach ($extensions as $extension) {
            $dispatcher->registerExtension($extension);
        }
        $notification = new Notification($type, 'Ama started following you.', 'user', 'id-1', $data, 'u_1', '1');

        $result = $dispatcher->send($notification, $this->notifiable(), ['recording']);

        self::assertSame('success', $result['channels']['recording']['status']);
    }

    public function testChannelsReceiveTheSubject(): void
    {
        $this->dispatch([]);

        self::assertSame('Ama started following you.', $this->received[0]['subject']);
        self::assertSame('hi', $this->received[0]['body']);
    }

    public function testASubjectInTheDataIsKept(): void
    {
        $this->dispatch([], ['subject' => 'Set by the sender']);

        self::assertSame('Set by the sender', $this->received[0]['subject']);
    }

    public function testAWildcardExtensionHandlesEveryType(): void
    {
        $this->dispatch([$this->extension('all', ['*']), $this->extension('mentions', ['mention'])]);

        self::assertSame(['all'], $this->received[0]['handled_by']);
        self::assertSame(['all'], $this->afterSendCalls);
    }

    public function testANamedTypeStillMatchesExactly(): void
    {
        $this->dispatch([$this->extension('follows', ['follow']), $this->extension('mentions', ['mention'])]);

        self::assertSame(['follows'], $this->received[0]['handled_by']);
    }

    /** @var list<string> */
    private array $afterSendCalls = [];

    /** @param list<string> $types */
    private function extension(string $name, array $types): NotificationExtension
    {
        $calls = &$this->afterSendCalls;

        return new class ($name, $types, $calls) implements NotificationExtension {
            /**
             * @param list<string> $types
             * @param list<string> $calls
             */
            public function __construct(private string $name, private array $types, private array &$calls)
            {
            }

            public function getExtensionName(): string
            {
                return $this->name;
            }

            public function initialize(array $config = []): bool
            {
                return true;
            }

            public function getSupportedNotificationTypes(): array
            {
                return $this->types;
            }

            public function beforeSend(array $data, Notifiable $notifiable, string $channel): array
            {
                $data['handled_by'] = [...($data['handled_by'] ?? []), $this->name];

                return $data;
            }

            public function afterSend(array $data, Notifiable $notifiable, string $channel, bool $success): void
            {
                $this->calls[] = $this->name;
            }
        };
    }

    private function recordingChannel(): NotificationChannel
    {
        $received = &$this->received;

        return new class ($received) implements NotificationChannel {
            /** @param list<array<string, mixed>> $received */
            public function __construct(private array &$received)
            {
            }

            public function getChannelName(): string
            {
                return 'recording';
            }

            public function send(Notifiable $notifiable, array $data): bool
            {
                $this->received[] = $data;

                return true;
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
