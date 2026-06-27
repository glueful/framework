<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\DTOs\Responses;

/**
 * Documentation-only list envelope for
 * {@see \Glueful\Api\Webhooks\Http\Controllers\WebhookController::listDeliveries()}. Doc-only.
 */
final class WebhookDeliveryListData
{
    /** @var list<WebhookDeliveryData> */
    public array $deliveries = [];

    public ?PaginationData $pagination = null;
}
