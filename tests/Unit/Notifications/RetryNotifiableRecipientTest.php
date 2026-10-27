<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Notifications;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Notifications\Contracts\NotificationStoreInterface;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Services\NotificationRetryService;
use PHPUnit\Framework\TestCase;

/**
 * Retries rebuild the recipient from the stored type and id. The rebuilt user routed nothing for
 * 'email', so every email retry failed with no_recipient — the same gap the scheduled path had.
 */
final class RetryNotifiableRecipientTest extends TestCase
{
    private function rebuild(?ApplicationContext $context, string $id): ?Notifiable
    {
        $service = new NotificationRetryService(
            notificationRepository: $this->createMock(NotificationStoreInterface::class),
            context: $context
        );
        $method = new \ReflectionMethod($service, 'createNotifiableEntity');

        /** @var Notifiable|null */
        return $method->invoke($service, 'user', $id);
    }

    private function context(): ApplicationContext
    {
        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('findByUuid')->willReturnCallback(
            static fn(string $uuid) => $uuid === 'user-1'
                ? new UserIdentity('user-1', email: 'ada@example.com')
                : null
        );
        $context = new ApplicationContext(sys_get_temp_dir() . '/retry_notifiable_' . uniqid());
        $context->setContainer(new Container([UserProviderInterface::class => $provider]));

        return $context;
    }

    public function testRebuiltUserRoutesEmailToTheirAddress(): void
    {
        $notifiable = $this->rebuild($this->context(), 'user-1');

        self::assertNotNull($notifiable);
        self::assertSame('ada@example.com', $notifiable->routeNotificationFor('email'));
        self::assertSame('user-1', $notifiable->getNotifiableId());
        self::assertSame('user', $notifiable->getNotifiableType());
    }

    public function testAUserThatNoLongerExistsYieldsNoRecipient(): void
    {
        // The caller drops the retry when there is no recipient to send to.
        self::assertNull($this->rebuild($this->context(), 'gone'));
    }

    public function testWithoutAContextTheRecipientIsStillBuilt(): void
    {
        $notifiable = $this->rebuild(null, 'user-1');

        self::assertNotNull($notifiable);
        self::assertNull($notifiable->routeNotificationFor('email'));
    }
}
