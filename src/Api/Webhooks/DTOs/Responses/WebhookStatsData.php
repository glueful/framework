<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\DTOs\Responses;

/**
 * Documentation-only delivery-statistics response for
 * {@see \Glueful\Api\Webhooks\Http\Controllers\WebhookController::getSubscriptionStats()}. Doc-only.
 */
final class WebhookStatsData
{
    /** Subscription UUID. */
    public string $uuid = '';

    /** Window the stats cover, in days. */
    public int $period_days = 30;

    /** Total deliveries in the window. */
    public int $total_deliveries = 0;

    /** Delivered count. */
    public int $delivered = 0;

    /** Failed count. */
    public int $failed = 0;

    /** Pending count. */
    public int $pending = 0;

    /** Success rate as a percentage (0–100). */
    public float $success_rate = 0.0;
}
