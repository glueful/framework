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
