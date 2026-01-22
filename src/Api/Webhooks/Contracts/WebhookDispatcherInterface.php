<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\Contracts;

use Glueful\Api\Webhooks\WebhookDelivery;

/**
 * Contract for webhook dispatching
 */
interface WebhookDispatcherInterface
{
    /**
     * Dispatch a webhook event to all matching subscribers
     *
     * @param string $event Event name (e.g., 'user.created')
     * @param array<string, mixed> $data Event data
     * @param array<string, mixed> $options Additional options
     * @return array<WebhookDelivery> Created delivery records
     */
    public function dispatch(string $event, array $data, array $options = []): array;
}
