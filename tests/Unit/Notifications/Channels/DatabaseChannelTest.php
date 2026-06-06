<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Notifications\Channels;

use Glueful\Notifications\Channels\DatabaseChannel;
use Glueful\Notifications\Contracts\Notifiable;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1: the core `database` channel.
 *
 * It does NOT persist the notification (NotificationService already saves it before
 * dispatch) and it does NOT write delivery records (the service owns ensureDeliveryRecords/
 * recordDeliveryAttempt). It only *acknowledges* in-app delivery: success when persistence
 * is active, unavailable when it is off.
 */
final class DatabaseChannelTest extends TestCase
{
    public function testChannelIsNamedDatabase(): void
    {
        self::assertSame('database', (new DatabaseChannel())->getChannelName());
    }

    public function testAvailableAndAcknowledgesWhenPersistenceEnabled(): void
    {
        $channel = new DatabaseChannel(persistenceEnabled: true);
        self::assertTrue($channel->isAvailable());
        self::assertTrue($channel->send($this->notifiable(), ['subject' => 'hi']));
    }

    public function testUnavailableAndNeverSucceedsWhenPersistenceDisabled(): void
    {
        $channel = new DatabaseChannel(persistenceEnabled: false);
        self::assertFalse($channel->isAvailable());
        self::assertFalse($channel->send($this->notifiable(), ['subject' => 'hi']));
    }

    public function testFormatReturnsDataUnchanged(): void
    {
        $channel = new DatabaseChannel();
        $data = ['subject' => 'X', '_meta' => ['delivery_idempotency_key' => 'u_1:database']];
        self::assertSame($data, $channel->format($data, $this->notifiable()));
    }

    /**
     * Structural guarantee against double-persist: the channel holds no Connection or
     * repository, so it cannot write a second notification/delivery row.
     */
    public function testHoldsNoPersistenceDependency(): void
    {
        $ctor = (new \ReflectionClass(DatabaseChannel::class))->getConstructor();
        foreach ($ctor?->getParameters() ?? [] as $param) {
            $type = $param->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            self::assertStringNotContainsString('Connection', $name);
            self::assertStringNotContainsString('Repository', $name);
        }
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
