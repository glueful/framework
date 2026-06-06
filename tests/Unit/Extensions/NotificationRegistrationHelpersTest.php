<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Extensions\ServiceProvider;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationChannel;
use Glueful\Notifications\Contracts\NotificationExtension;
use Glueful\Notifications\Exceptions\ChannelAlreadyRegisteredException;
use Glueful\Notifications\Services\ChannelManager;
use Glueful\Notifications\Services\NotificationDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Phase 5 / Task 5b: the extension ServiceProvider helpers register into the SHARED container
 * ChannelManager / NotificationDispatcher, and channel-name conflicts surface through the helper.
 */
final class NotificationRegistrationHelpersTest extends TestCase
{
    public function testRegisterNotificationChannelAddsToSharedManager(): void
    {
        $cm = new ChannelManager();
        $provider = $this->provider($cm, new NotificationDispatcher($cm, null, [], null));

        $provider->registerChannelForTest($this->channelA('webhook'));

        self::assertTrue($cm->hasChannel('webhook'));
    }

    public function testRegisterNotificationExtensionAddsToSharedDispatcher(): void
    {
        $cm = new ChannelManager();
        $dispatcher = new NotificationDispatcher($cm, null, [], null);
        $provider = $this->provider($cm, $dispatcher);
        $ext = $this->extension('my_ext');

        $provider->registerExtensionForTest($ext);

        self::assertSame($ext, $dispatcher->getExtension('my_ext'));
    }

    public function testChannelNameConflictSurfacesThroughHelper(): void
    {
        $cm = new ChannelManager();
        $cm->registerChannel($this->channelA('email'));
        $provider = $this->provider($cm, new NotificationDispatcher($cm, null, [], null));

        $this->expectException(ChannelAlreadyRegisteredException::class);
        $provider->registerChannelForTest($this->channelB('email')); // different class, same name
    }

    private function provider(ChannelManager $cm, NotificationDispatcher $dispatcher): object
    {
        $container = new class ($cm, $dispatcher) implements ContainerInterface {
            public function __construct(private ChannelManager $cm, private NotificationDispatcher $dispatcher)
            {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    ChannelManager::class => $this->cm,
                    NotificationDispatcher::class => $this->dispatcher,
                    default => throw new \RuntimeException("Unbound: {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [ChannelManager::class, NotificationDispatcher::class], true);
            }
        };

        return new class ($container) extends ServiceProvider {
            public function registerChannelForTest(NotificationChannel $channel): void
            {
                $this->registerNotificationChannel($channel);
            }

            public function registerExtensionForTest(NotificationExtension $extension): void
            {
                $this->registerNotificationExtension($extension);
            }
        };
    }

    private function channelA(string $name): NotificationChannel
    {
        return new class ($name) implements NotificationChannel {
            public function __construct(private string $name)
            {
            }

            public function getChannelName(): string
            {
                return $this->name;
            }

            public function send(Notifiable $notifiable, array $data): bool
            {
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

    private function channelB(string $name): NotificationChannel
    {
        return new class ($name) implements NotificationChannel {
            public function __construct(private string $name)
            {
            }

            public function getChannelName(): string
            {
                return $this->name;
            }

            public function send(Notifiable $notifiable, array $data): bool
            {
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

    private function extension(string $name): NotificationExtension
    {
        return new class ($name) implements NotificationExtension {
            public function __construct(private string $name)
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
                return ['*'];
            }

            public function beforeSend(array $data, Notifiable $notifiable, string $channel): array
            {
                return $data;
            }

            public function afterSend(array $data, Notifiable $notifiable, string $channel, bool $success): void
            {
            }
        };
    }
}
