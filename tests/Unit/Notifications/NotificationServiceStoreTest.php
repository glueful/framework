<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Notifications;

use Glueful\Notifications\Contracts\NotificationStoreInterface;
use Glueful\Notifications\Exceptions\NotificationPersistenceDisabledException;
use Glueful\Notifications\Services\NotificationDispatcher;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Notifications\Stores\NullNotificationStore;
use Glueful\Repository\NotificationRepository;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2: NotificationService depends on the store seam.
 * - Accepts a NotificationRepository (backward compat — repo is-a store).
 * - getStore() always returns the bound store.
 * - getRepository() returns the repo when persistence is on, throws when off.
 */
final class NotificationServiceStoreTest extends TestCase
{
    private function service(NotificationStoreInterface $store): NotificationService
    {
        return new NotificationService(
            $this->createMock(NotificationDispatcher::class),
            $store,
            null,
            null,
            []
        );
    }

    public function testAcceptsRepositoryForBackwardCompat(): void
    {
        // Existing callers: new NotificationService($dispatcher, new NotificationRepository()).
        // The repo is-a store, so it flows straight through.
        $repo = $this->createMock(NotificationRepository::class);
        self::assertSame($repo, $this->service($repo)->getStore());
    }

    public function testGetStoreReturnsBoundStore(): void
    {
        $store = new NullNotificationStore();
        self::assertSame($store, $this->service($store)->getStore());
    }

    public function testGetRepositoryReturnsRepoWhenPersistenceEnabled(): void
    {
        $repo = $this->createMock(NotificationRepository::class);
        $service = $this->service($repo);
        self::assertSame($repo, $service->getRepository());
        self::assertSame($repo, $service->getStore());
    }

    public function testGetRepositoryThrowsWhenPersistenceDisabled(): void
    {
        $service = $this->service(new NullNotificationStore());
        $this->expectException(NotificationPersistenceDisabledException::class);
        $service->getRepository();
    }
}
