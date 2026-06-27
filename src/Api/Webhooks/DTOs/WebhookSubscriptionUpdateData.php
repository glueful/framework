<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\DTOs;

/**
 * Documentation-only request body for
 * {@see \Glueful\Api\Webhooks\Http\Controllers\WebhookController::updateSubscription()}.
 *
 * Partial update — every field is optional; only supplied fields change. Doc-only (never hydrated);
 * reflected for #[ApiRequestBody].
 */
final class WebhookSubscriptionUpdateData
{
    /** New endpoint URL. */
    public ?string $url = null;

    /**
     * Replacement event list (non-empty when supplied).
     *
     * @var list<string>|null
     */
    public ?array $events = null;

    /** Pause/resume delivery. */
    public ?bool $is_active = null;

    /**
     * Replacement metadata.
     *
     * @var array<string,mixed>|null
     */
    public ?array $metadata = null;
}
