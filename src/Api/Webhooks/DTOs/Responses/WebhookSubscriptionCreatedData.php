<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\DTOs\Responses;

/**
 * Documentation-only create response for
 * {@see \Glueful\Api\Webhooks\Http\Controllers\WebhookController::createSubscription()}: the new
 * subscription PLUS the signing `secret`, which is returned only here (and on rotate). Doc-only —
 * reflected for #[ApiResponse], never constructed at runtime.
 */
final class WebhookSubscriptionCreatedData
{
    /** Subscription UUID (e.g. wh_sub_…). */
    public string $uuid = '';

    /** Endpoint URL. */
    public string $url = '';

    /**
     * Subscribed event patterns.
     *
     * @var list<string>
     */
    public array $events = [];

    /** Whether the subscription is active. */
    public bool $is_active = true;

    /**
     * Arbitrary metadata stored with the subscription.
     *
     * @var array<string,mixed>|null
     */
    public ?array $metadata = null;

    /** Creation timestamp. */
    public ?string $created_at = null;

    /** Last-update timestamp. */
    public ?string $updated_at = null;

    /** The signing secret (e.g. whsec_…) — shown once, used to verify the X-Webhook-Signature. */
    public string $secret = '';
}
