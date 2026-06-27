<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\DTOs\Responses;

/**
 * Documentation-only list envelope for
 * {@see \Glueful\Api\Webhooks\Http\Controllers\WebhookController::listSubscriptions()}. Doc-only.
 */
final class WebhookSubscriptionListData
{
    /** @var list<WebhookSubscriptionData> */
    public array $subscriptions = [];

    public ?PaginationData $pagination = null;
}
