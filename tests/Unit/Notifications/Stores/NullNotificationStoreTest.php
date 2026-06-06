<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Notifications\Stores;

use Glueful\Notifications\Exceptions\NotificationPersistenceDisabledException;
use Glueful\Notifications\Models\Notification;
use Glueful\Notifications\Models\NotificationPreference;
use Glueful\Notifications\Stores\NullNotificationStore;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2: the no-DB store degrades explicitly per the no-DB matrix.
 *  - reads/counts        → empty / null / zero
 *  - fire-and-forget     → silent no-op (transient save, delivery-record writes)
 *  - durability-implying → throw NotificationPersistenceDisabledException
 */
final class NullNotificationStoreTest extends TestCase
{
    private NullNotificationStore $store;

    protected function setUp(): void
    {
        $this->store = new NullNotificationStore();
    }

    public function testIsNotPersistent(): void
    {
        self::assertFalse($this->store->isPersistent());
    }

    public function testReadsReturnEmptyNullOrZero(): void
    {
        self::assertNull($this->store->findByUuid('u_1'));
        self::assertSame([], $this->store->findForNotifiable('user', 'id-1'));
        self::assertSame([], $this->store->findForNotifiableWithPagination('user', 'id-1'));
        self::assertSame([], $this->store->findPendingScheduled());
        self::assertNull($this->store->findRecentByIdempotencyKey('user', 'id-1', 't', 'k', 300));
        self::assertSame([], $this->store->getChannelsNeedingDispatch('u_1', ['database']));
        self::assertSame([], $this->store->getFailedDeliveryChannels('u_1'));
        self::assertSame([], $this->store->findPreferencesForNotifiable('user', 'id-1'));
        self::assertSame(0, $this->store->countForNotifiable('user', 'id-1'));
    }

    public function testFireAndForgetWritesAreSilentNoOps(): void
    {
        // No exceptions; save acknowledges ephemerally.
        self::assertTrue($this->store->save($this->notification()));
        $this->store->ensureDeliveryRecords('u_1', ['database']);
        $this->store->recordDeliveryAttempt('u_1', 'database', 'sent');
        $this->addToAssertionCount(1);
    }

    public function testSavePreferenceThrows(): void
    {
        $this->expectException(NotificationPersistenceDisabledException::class);
        $this->store->savePreference($this->preference());
    }

    public function testMarkAllAsReadThrows(): void
    {
        $this->expectException(NotificationPersistenceDisabledException::class);
        $this->store->markAllAsRead('user', 'id-1');
    }

    public function testDeleteOldNotificationsThrows(): void
    {
        $this->expectException(NotificationPersistenceDisabledException::class);
        $this->store->deleteOldNotifications(30);
    }

    private function notification(): Notification
    {
        return new Notification('test_type', 'Subject', 'user', 'id-1', ['body' => 'hi'], 'u_1', '1');
    }

    private function preference(): NotificationPreference
    {
        return new NotificationPreference('p_1', 'user', 'id-1', 'test_type');
    }
}
