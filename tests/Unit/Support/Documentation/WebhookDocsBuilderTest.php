<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Support\Documentation\WebhookDocsBuilder;
use PHPUnit\Framework\TestCase;

final class WebhookDocsBuilderTest extends TestCase
{
    public function testBuildsWebhooksSection(): void
    {
        $builder = new WebhookDocsBuilder();
        $webhooks = $builder->build([
            'user.created' => ['summary' => 'A new user', 'payload_schema' => 'User'],
            'order.shipped' => ['summary' => 'Order shipped', 'payload_schema' => 'Order'],
        ]);

        self::assertArrayHasKey('user.created', $webhooks);
        $userCreated = $webhooks['user.created'];
        self::assertSame('A new user', $userCreated['post']['summary']);

        $schema = $userCreated['post']['requestBody']['content']['application/json']['schema'];
        self::assertArrayHasKey('allOf', $schema);
        self::assertSame('#/components/schemas/WebhookEnvelope', $schema['allOf'][0]['$ref']);
        self::assertSame(
            '#/components/schemas/User',
            $schema['allOf'][1]['properties']['data']['$ref'],
        );

        self::assertSame('onUserCreated', $userCreated['post']['operationId']);

        $params = $userCreated['post']['parameters'];
        $sigHeader = array_values(array_filter($params, static fn ($p) => $p['name'] === 'X-Glueful-Signature'));
        $tsHeader = array_values(array_filter($params, static fn ($p) => $p['name'] === 'X-Glueful-Timestamp'));

        self::assertCount(1, $sigHeader);
        self::assertSame('header', $sigHeader[0]['in']);
        self::assertTrue($sigHeader[0]['required']);
        self::assertSame('string', $sigHeader[0]['schema']['type']);
        self::assertStringContainsString('HMAC', $sigHeader[0]['description']);

        self::assertCount(1, $tsHeader);
        self::assertSame('integer', $tsHeader[0]['schema']['type']);
    }

    public function testReturnsEmptyArrayWhenConfigEmpty(): void
    {
        $builder = new WebhookDocsBuilder();
        self::assertSame([], $builder->build([]));
    }

    public function testFallsBackWhenPayloadSchemaMissing(): void
    {
        $builder = new WebhookDocsBuilder();
        $webhooks = $builder->build(['heartbeat' => []]);

        $schema = $webhooks['heartbeat']['post']['requestBody']['content']['application/json']['schema'];
        // No allOf when there's no specific payload schema — just the envelope ref
        self::assertSame('#/components/schemas/WebhookEnvelope', $schema['$ref']);
    }
}
