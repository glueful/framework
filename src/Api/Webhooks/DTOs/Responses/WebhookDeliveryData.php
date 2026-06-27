<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\DTOs\Responses;

/**
 * Documentation-only shape of one webhook delivery row (list view) as returned by
 * {@see \Glueful\Api\Webhooks\Http\Controllers\WebhookController}. Doc-only — reflected for
 * #[ApiResponse].
 */
final class WebhookDeliveryData
{
    /** Delivery UUID (e.g. wh_del_…). */
    public string $uuid = '';

    /** Event name that triggered the delivery. */
    public string $event = '';

    /** Delivery status: pending | delivered | failed | retrying. */
    public string $status = 'pending';

    /** Number of attempts made. */
    public int $attempts = 0;

    /** HTTP status returned by the endpoint (null until attempted). */
    public ?int $response_code = null;

    /** When the delivery succeeded. */
    public ?string $delivered_at = null;

    /** When the next retry is scheduled. */
    public ?string $next_retry_at = null;

    /** Creation timestamp. */
    public ?string $created_at = null;
}
