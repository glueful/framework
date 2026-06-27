<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\DTOs\Responses;

/**
 * Documentation-only shape of one webhook subscription as returned by
 * {@see \Glueful\Api\Webhooks\Http\Controllers\WebhookController}. The signing `secret` is NOT
 * included on reads (only on create/rotate). Doc-only — reflected for #[ApiResponse].
 */
final class WebhookSubscriptionData
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

    /** Whether the subscription is active (paused subscriptions receive no deliveries). */
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
}
