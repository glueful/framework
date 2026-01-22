<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Webhooks;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Glueful\Api\Webhooks\WebhookPayload;

class WebhookPayloadTest extends TestCase
{
    private WebhookPayload $payloadBuilder;

    protected function setUp(): void
    {
        $this->payloadBuilder = new WebhookPayload();
    }

    #[Test]
    public function buildCreatesValidPayload(): void
    {
        $event = 'user.created';
        $data = ['id' => 123, 'name' => 'John'];

        $payload = $this->payloadBuilder->build($event, $data);

        $this->assertArrayHasKey('id', $payload);
        $this->assertArrayHasKey('event', $payload);
        $this->assertArrayHasKey('created_at', $payload);
        $this->assertArrayHasKey('data', $payload);
    }

    #[Test]
    public function buildIncludesEventName(): void
    {
        $event = 'order.completed';
        $data = ['order_id' => 456];

        $payload = $this->payloadBuilder->build($event, $data);

        $this->assertEquals($event, $payload['event']);
    }

    #[Test]
    public function buildIncludesDataUnmodified(): void
    {
        $event = 'test.event';
        $data = [
            'string' => 'value',
            'number' => 42,
            'nested' => ['key' => 'value'],
        ];

        $payload = $this->payloadBuilder->build($event, $data);

        $this->assertEquals($data, $payload['data']);
    }

    #[Test]
    public function buildGeneratesUniqueIds(): void
    {
        $event = 'test.event';
        $data = [];

        $payload1 = $this->payloadBuilder->build($event, $data);
        $payload2 = $this->payloadBuilder->build($event, $data);

        $this->assertNotEquals($payload1['id'], $payload2['id']);
    }

    #[Test]
    public function buildIncludesValidTimestamp(): void
    {
        $before = time();
        $payload = $this->payloadBuilder->build('test', []);
        $after = time();

        $timestamp = strtotime($payload['created_at']);
        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
    }

    #[Test]
    public function buildIncludesMetadataWhenProvided(): void
    {
        $event = 'test.event';
        $data = ['key' => 'value'];
        $metadata = ['source' => 'test', 'version' => '1.0'];

        $payload = $this->payloadBuilder->build($event, $data, $metadata);

        $this->assertArrayHasKey('metadata', $payload);
        $this->assertEquals($metadata, $payload['metadata']);
    }

    #[Test]
    public function buildOmitsMetadataWhenEmpty(): void
    {
        $payload = $this->payloadBuilder->build('test', []);

        $this->assertArrayNotHasKey('metadata', $payload);
    }

    #[Test]
    public function buildHandlesEmptyData(): void
    {
        $payload = $this->payloadBuilder->build('empty.event', []);

        $this->assertEquals([], $payload['data']);
    }
}
