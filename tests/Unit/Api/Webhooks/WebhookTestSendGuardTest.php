<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Webhooks;

use Glueful\Api\Webhooks\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Webhook::test() posted to whatever URL it was given; only the queued delivery checked the
 * destination. An admin's "Send test event" could reach loopback, the private network or the
 * cloud metadata address. It now applies the delivery's own guard before any request is made.
 */
final class WebhookTestSendGuardTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function forbidden(): array
    {
        return [
            'loopback' => ['http://127.0.0.1:9/hook'],
            'metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'private' => ['http://10.0.0.5/hook'],
            'localhost' => ['http://localhost/hook'],
            'not http' => ['file:///etc/passwd'],
        ];
    }

    #[DataProvider('forbidden')]
    public function testATestSendToAForbiddenDestinationIsRefusedBeforeAnyRequest(string $url): void
    {
        $result = Webhook::test($url);

        self::assertFalse($result['success']);
        self::assertArrayNotHasKey('status_code', $result);
        self::assertMatchesRegularExpression('/not allowed|must use http/', (string) ($result['error'] ?? ''));
    }
}
