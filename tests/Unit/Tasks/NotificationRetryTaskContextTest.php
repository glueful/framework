<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Tasks;

use Glueful\Notifications\Exceptions\NotificationContextRequiredException;
use Glueful\Notifications\Services\NotificationRetryService;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Tasks\NotificationRetryTask;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 / Task 5c: notification dispatch requires a container context. With no context and no
 * injected services, the task fails loudly instead of building ad-hoc managers.
 */
final class NotificationRetryTaskContextTest extends TestCase
{
    public function testThrowsWhenNoContextAndNoServicesInjected(): void
    {
        $this->expectException(NotificationContextRequiredException::class);
        new NotificationRetryTask();
    }

    public function testConstructsWithInjectedServicesWithoutContext(): void
    {
        // Fully-injected construction needs no context (the container path is skipped).
        $this->expectNotToPerformAssertions();
        new NotificationRetryTask(
            $this->createMock(NotificationRetryService::class),
            $this->createMock(NotificationService::class)
        );
    }
}
