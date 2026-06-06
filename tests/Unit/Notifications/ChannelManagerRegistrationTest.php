<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Notifications;

use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationChannel;
use Glueful\Notifications\Exceptions\ChannelAlreadyRegisteredException;
use Glueful\Notifications\Services\ChannelManager;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 / Task 5a: ChannelManager registration policy + renamed name accessors.
 *  - register new name → registered
 *  - same name + same class → idempotent no-op
 *  - same name + different class → throws
 *  - replaceChannel() overwrites by name
 *  - getRegisteredChannelNames() = all; getActiveChannelNames() = isAvailable() only
 */
final class ChannelManagerRegistrationTest extends TestCase
{
    public function testRegistersANewChannel(): void
    {
        $manager = new ChannelManager();
        $manager->registerChannel($this->channelA('sms'));

        self::assertTrue($manager->hasChannel('sms'));
        self::assertContains('sms', $manager->getRegisteredChannelNames());
    }

    public function testSameClassReRegistrationIsIdempotentNoOp(): void
    {
        $manager = new ChannelManager();
        $manager->registerChannel($this->channelA('email'));
        $manager->registerChannel($this->channelA('email')); // same anonymous class → no-op

        self::assertSame(['email'], $manager->getRegisteredChannelNames());
    }

    public function testDifferentClassOnSameNameThrows(): void
    {
        $manager = new ChannelManager();
        $manager->registerChannel($this->channelA('email'));

        $this->expectException(ChannelAlreadyRegisteredException::class);
        $manager->registerChannel($this->channelB('email')); // different class, same name
    }

    public function testReplaceChannelOverwritesByName(): void
    {
        $manager = new ChannelManager();
        $original = $this->channelA('email');
        $replacement = $this->channelB('email');
        $manager->registerChannel($original);

        $manager->replaceChannel($replacement);

        self::assertSame($replacement, $manager->getChannel('email'));
    }

    public function testNameAccessorsDistinguishRegisteredFromActive(): void
    {
        $manager = new ChannelManager();
        $manager->registerChannel($this->channelA('on', available: true));
        $manager->registerChannel($this->channelB('off', available: false));

        self::assertEqualsCanonicalizing(['on', 'off'], $manager->getRegisteredChannelNames());
        self::assertSame(['on'], $manager->getActiveChannelNames());
    }

    private function channelA(string $name, bool $available = true): NotificationChannel
    {
        return new class ($name, $available) implements NotificationChannel {
            public function __construct(private string $name, private bool $available)
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
                return $this->available;
            }

            public function getConfig(): array
            {
                return [];
            }
        };
    }

    private function channelB(string $name, bool $available = true): NotificationChannel
    {
        return new class ($name, $available) implements NotificationChannel {
            public function __construct(private string $name, private bool $available)
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
                return $this->available;
            }

            public function getConfig(): array
            {
                return [];
            }
        };
    }
}
