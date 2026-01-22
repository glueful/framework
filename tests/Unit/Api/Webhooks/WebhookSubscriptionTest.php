<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Webhooks;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Glueful\Api\Webhooks\WebhookSubscription;

class WebhookSubscriptionTest extends TestCase
{
    #[Test]
    public function listensToMatchesExactEvent(): void
    {
        $subscription = new WebhookSubscription();
        $subscription->events = ['user.created'];

        $this->assertTrue($subscription->listensTo('user.created'));
        $this->assertFalse($subscription->listensTo('user.updated'));
    }

    #[Test]
    public function listensToMatchesWildcardAll(): void
    {
        $subscription = new WebhookSubscription();
        $subscription->events = ['*'];

        $this->assertTrue($subscription->listensTo('user.created'));
        $this->assertTrue($subscription->listensTo('order.completed'));
        $this->assertTrue($subscription->listensTo('any.event.name'));
    }

    #[Test]
    public function listensToMatchesPrefixWildcard(): void
    {
        $subscription = new WebhookSubscription();
        $subscription->events = ['user.*'];

        $this->assertTrue($subscription->listensTo('user.created'));
        $this->assertTrue($subscription->listensTo('user.updated'));
        $this->assertTrue($subscription->listensTo('user.deleted'));
        $this->assertFalse($subscription->listensTo('order.created'));
        $this->assertFalse($subscription->listensTo('users.created'));
    }

    #[Test]
    public function listensToMatchesMultiplePatterns(): void
    {
        $subscription = new WebhookSubscription();
        $subscription->events = ['user.created', 'order.*'];

        $this->assertTrue($subscription->listensTo('user.created'));
        $this->assertFalse($subscription->listensTo('user.updated'));
        $this->assertTrue($subscription->listensTo('order.created'));
        $this->assertTrue($subscription->listensTo('order.completed'));
    }

    #[Test]
    #[DataProvider('wildcardMatchingProvider')]
    public function listensToHandlesVariousWildcardPatterns(
        array $events,
        string $eventToCheck,
        bool $expected
    ): void {
        $subscription = new WebhookSubscription();
        $subscription->events = $events;

        $this->assertEquals($expected, $subscription->listensTo($eventToCheck));
    }

    /**
     * @return array<string, array{0: array<string>, 1: string, 2: bool}>
     */
    public static function wildcardMatchingProvider(): array
    {
        return [
            'exact match' => [['user.created'], 'user.created', true],
            'exact no match' => [['user.created'], 'user.updated', false],
            'wildcard all' => [['*'], 'anything', true],
            'prefix wildcard match' => [['user.*'], 'user.created', true],
            'prefix wildcard no match' => [['user.*'], 'order.created', false],
            'multiple events one matches' => [['user.created', 'order.created'], 'order.created', true],
            'multiple events none match' => [['user.created', 'order.created'], 'payment.failed', false],
            'prefix wildcard exact prefix no match' => [['user.*'], 'user', false],
            'deep nesting with wildcard' => [['api.*'], 'api.v1.users.created', true],
        ];
    }

    #[Test]
    public function generateSecretCreatesSecureSecret(): void
    {
        $secret1 = WebhookSubscription::generateSecret();
        $secret2 = WebhookSubscription::generateSecret();

        // Should start with whsec_ prefix
        $this->assertStringStartsWith('whsec_', $secret1);
        $this->assertStringStartsWith('whsec_', $secret2);

        // Should be unique
        $this->assertNotEquals($secret1, $secret2);

        // Should be long enough (whsec_ + 64 hex chars = 70 chars)
        $this->assertEquals(70, strlen($secret1));
    }

    #[Test]
    public function emptyEventsMatchesNothing(): void
    {
        $subscription = new WebhookSubscription();
        $subscription->events = [];

        $this->assertFalse($subscription->listensTo('user.created'));
        $this->assertFalse($subscription->listensTo('*'));
    }
}
