<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Webhooks;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Glueful\Api\Webhooks\WebhookDelivery;

class WebhookDeliveryTest extends TestCase
{
    #[Test]
    public function isPendingReturnsTrueForPendingStatus(): void
    {
        $delivery = new WebhookDelivery();
        $delivery->status = WebhookDelivery::STATUS_PENDING;

        $this->assertTrue($delivery->isPending());
        $this->assertFalse($delivery->isDelivered());
        $this->assertFalse($delivery->isFailed());
        $this->assertFalse($delivery->isRetrying());
    }

    #[Test]
    public function isDeliveredReturnsTrueForDeliveredStatus(): void
    {
        $delivery = new WebhookDelivery();
        $delivery->status = WebhookDelivery::STATUS_DELIVERED;

        $this->assertFalse($delivery->isPending());
        $this->assertTrue($delivery->isDelivered());
        $this->assertFalse($delivery->isFailed());
        $this->assertFalse($delivery->isRetrying());
    }

    #[Test]
    public function isFailedReturnsTrueForFailedStatus(): void
    {
        $delivery = new WebhookDelivery();
        $delivery->status = WebhookDelivery::STATUS_FAILED;

        $this->assertFalse($delivery->isPending());
        $this->assertFalse($delivery->isDelivered());
        $this->assertTrue($delivery->isFailed());
        $this->assertFalse($delivery->isRetrying());
    }

    #[Test]
    public function isRetryingReturnsTrueForRetryingStatus(): void
    {
        $delivery = new WebhookDelivery();
        $delivery->status = WebhookDelivery::STATUS_RETRYING;

        $this->assertFalse($delivery->isPending());
        $this->assertFalse($delivery->isDelivered());
        $this->assertFalse($delivery->isFailed());
        $this->assertTrue($delivery->isRetrying());
    }

    #[Test]
    public function getRetryDelayReturnsNullWhenNoRetryScheduled(): void
    {
        $delivery = new WebhookDelivery();
        $delivery->next_retry_at = null;

        $this->assertNull($delivery->getRetryDelay());
    }

    #[Test]
    public function getRetryDelayReturnsPositiveDelayForFutureRetry(): void
    {
        $delivery = new WebhookDelivery();
        $futureTime = date('Y-m-d H:i:s', strtotime('+5 minutes'));
        $delivery->next_retry_at = $futureTime;

        $delay = $delivery->getRetryDelay();
        $this->assertNotNull($delay);
        $this->assertGreaterThan(0, $delay);
        $this->assertLessThanOrEqual(300, $delay);
    }

    #[Test]
    public function getRetryDelayReturnsZeroForPastRetry(): void
    {
        $delivery = new WebhookDelivery();
        $pastTime = date('Y-m-d H:i:s', strtotime('-5 minutes'));
        $delivery->next_retry_at = $pastTime;

        $this->assertEquals(0, $delivery->getRetryDelay());
    }

    #[Test]
    public function isReadyForRetryReturnsTrueWhenRetryingAndTimeHasPassed(): void
    {
        $delivery = new WebhookDelivery();
        $delivery->status = WebhookDelivery::STATUS_RETRYING;
        $delivery->next_retry_at = date('Y-m-d H:i:s', strtotime('-1 minute'));

        $this->assertTrue($delivery->isReadyForRetry());
    }

    #[Test]
    public function isReadyForRetryReturnsFalseWhenNotRetrying(): void
    {
        $delivery = new WebhookDelivery();
        $delivery->status = WebhookDelivery::STATUS_PENDING;
        $delivery->next_retry_at = date('Y-m-d H:i:s', strtotime('-1 minute'));

        $this->assertFalse($delivery->isReadyForRetry());
    }

    #[Test]
    public function isReadyForRetryReturnsFalseWhenRetryTimeNotReached(): void
    {
        $delivery = new WebhookDelivery();
        $delivery->status = WebhookDelivery::STATUS_RETRYING;
        $delivery->next_retry_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        $this->assertFalse($delivery->isReadyForRetry());
    }

    #[Test]
    public function statusConstantsHaveExpectedValues(): void
    {
        $this->assertEquals('pending', WebhookDelivery::STATUS_PENDING);
        $this->assertEquals('delivered', WebhookDelivery::STATUS_DELIVERED);
        $this->assertEquals('failed', WebhookDelivery::STATUS_FAILED);
        $this->assertEquals('retrying', WebhookDelivery::STATUS_RETRYING);
    }
}
