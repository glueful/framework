<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks\DTOs;

/**
 * Documentation-only request body for
 * {@see \Glueful\Api\Webhooks\Http\Controllers\WebhookController::createSubscription()}.
 *
 * The controller handles the request manually (Symfony Request), so this DTO is NEVER hydrated at
 * runtime — it is reflected by {@see \Glueful\Support\Documentation\ClassSchemaReflector} purely to
 * document the JSON body the route accepts (via #[ApiRequestBody]).
 */
final class WebhookSubscriptionInputData
{
    /** Endpoint URL to deliver events to (HTTPS required when api.webhooks.require_https is on). */
    public string $url = '';

    /**
     * Events to subscribe to. Supports exact names, `*` (all), and `prefix.*` wildcards.
     *
     * @var list<string>
     */
    public array $events = [];

    /**
     * Optional arbitrary metadata stored with the subscription.
     *
     * @var array<string,mixed>|null
     */
    public ?array $metadata = null;
}
