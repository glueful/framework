<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\Events;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Fired when webhooks are queued for delivery
 *
 * This event is dispatched internally when the WebhookDispatcher
 * successfully queues deliveries for an event.
 */
class WebhookDispatchedEvent extends BaseEvent
{
    /**
     * @param string $event The webhook event name that was dispatched
     * @param int $deliveryCount Number of deliveries created
     */
    public function __construct(
        public readonly string $event,
        public readonly int $deliveryCount
    ) {
        parent::__construct();
    }

    /**
     * Get the dispatched event name
     */
    public function getEvent(): string
    {
        return $this->event;
    }

    /**
     * Get the number of deliveries created
     */
    public function getDeliveryCount(): int
    {
        return $this->deliveryCount;
    }
}
