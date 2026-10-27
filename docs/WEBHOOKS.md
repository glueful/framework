# Webhooks

Glueful sends **outbound** webhooks: the app dispatches named events (`order.shipped`), and the
framework POSTs a signed JSON envelope to every matching subscription, on a queue, with retries.
This guide covers setup, dispatching, signatures, the management API, and how to declare webhook
events in the generated OpenAPI 3.1 spec.

## Setup

The `webhook_subscriptions` and `webhook_deliveries` tables ship as the core `webhooks` migration
leaf (`migrations/webhooks`, `glueful/framework:webhooks`):

```bash
php glueful migrate:run
php glueful queue:work --queue=webhooks
```

Configuration lives in the `webhooks` block of `config/api.php`. Every key is read:

| Key | Env | Default | Notes |
|-----|-----|---------|-------|
| `enabled` | `WEBHOOKS_ENABLED` | `true` | Off: `dispatch()` creates no deliveries and the event bridge isn't registered |
| `queue` | `WEBHOOKS_QUEUE` | `webhooks` | Queue for delivery jobs |
| `connection` | `WEBHOOKS_QUEUE_CONNECTION` | `null` | Queue connection; `null` = default connection |
| `signature_header` | | `X-Webhook-Signature` | Header carrying the signature |
| `signature_algorithm` | | `sha256` | `hash_hmac()` algorithm |
| `timeout` | `WEBHOOKS_TIMEOUT` | `30` | Per-request timeout (seconds) |
| `user_agent` | | `Glueful-Webhooks/1.0` | `User-Agent` header |
| `retry.max_attempts` | `WEBHOOKS_MAX_ATTEMPTS` | `5` | Attempts before a delivery is `failed` |
| `retry.backoff` | | `[60, 300, 1800, 7200, 43200]` | Delay after each failed attempt; the last value repeats |
| `require_https` | `WEBHOOKS_REQUIRE_HTTPS` | `true` | Reject non-HTTPS subscription URLs |
| `cleanup.keep_successful_days` / `keep_failed_days` | | `7` / `30` | Retention for `webhook_cleanup` |

## Subscribing and dispatching

```php
use Glueful\Api\Webhooks\Webhook;
use Glueful\Api\Webhooks\Contracts\WebhookDispatcherInterface;

$sub = Webhook::subscribe(['order.*'], 'https://partner.example.com/hooks');
$secret = $sub->secret; // whsec_..., share with the receiver

app($context, WebhookDispatcherInterface::class)->dispatch('order.shipped', [
    'order_id' => $order->uuid,
], [
    // optional per-dispatch overrides
    'queue' => 'webhooks',
    'connection' => 'redis',
    'delay' => 30, // seconds
]);
```

`dispatch()` creates a `pending` delivery per matching active subscription and queues a
`DeliverWebhookJob` for each. `WebhookDispatcher`'s constructor is
`(?WebhookPayloadInterface $payloadBuilder = null, ?ApplicationContext $context = null)`.

### From application events

Events that use the `DispatchesWebhooks` trait or carry `#[Webhookable]` become webhooks when
dispatched through `EventService`. The framework registers `WebhookEventListener` at boot when
`events.enabled` and `api.webhooks.enabled` are both on (priority `-100`, so app listeners run
first). Don't register it yourself, or each event dispatches twice. `webhookOptions()` on the trait
returns the same `queue` / `connection` / `delay` options. `#[Webhookable]`'s `only` / `except`
arguments are deprecated and have no effect.

## Signatures

Every outbound request — subscription deliveries and `Glueful\Http\Services\WebhookDeliveryService`
calls with a `secret` option — uses one scheme:

```
X-Webhook-Signature: t=<unix ts>,v1=<hex HMAC of "<ts>.<raw body>">
X-Webhook-Timestamp: <unix ts>
```

The header name and HMAC algorithm come from `signature_header` / `signature_algorithm`. Verify with
`Webhook::verify($rawBody, $header, $secret)` (configured algorithm, 300-second tolerance) or
`WebhookSignature::verify()`. External receivers compute
`hash_hmac('sha256', "{$t}.{$rawBody}", $secret)` and compare it in constant time with each `v*`
value.

> `WebhookDeliveryService` previously sent `X-Glueful-Signature: sha256=…` and
> `X-Glueful-Timestamp`. Receivers of its deliveries must switch to the scheme above.

## Management API

`routes/webhooks.php` mounts `WebhookController` under the API prefix (e.g. `/api/v1/webhooks`).
Every route requires `auth`; reads require `system.config.view`, changes (including creating,
rotating secrets, sending tests and retrying) require `system.config.edit`. `{id}` is the
subscription / delivery uuid.

| Method | Path |
|--------|------|
| `GET`, `POST` | `/webhooks/subscriptions` |
| `GET`, `PATCH`, `DELETE` | `/webhooks/subscriptions/{id}` |
| `POST` | `/webhooks/subscriptions/{id}/rotate-secret` |
| `POST` | `/webhooks/subscriptions/{id}/test` |
| `GET` | `/webhooks/subscriptions/{id}/stats` |
| `GET` | `/webhooks/deliveries`, `/webhooks/deliveries/{id}` |
| `POST` | `/webhooks/deliveries/{id}/retry` |

Remove any app routes that mounted `WebhookController` on the same paths.

## Declaring webhook events in OpenAPI

Add entries under `documentation.webhooks` in `config/documentation.php`:

```php
'webhooks' => [
    'user.created' => [
        'summary' => 'A new user has been created.',
        'payload_schema' => 'User',  // References #/components/schemas/User
    ],
    'heartbeat' => [
        'summary' => 'Periodic heartbeat with no specific payload.',
        // payload_schema omitted — uses the bare WebhookEnvelope
    ],
],
```

When `documentation.openapi_version` is `3.1.0` or higher (the default) and at least one webhook is
declared, the generator emits a top-level `webhooks` object. Each declared event produces:

- **An `operationId`** derived from the event name (`user.created` → `onUserCreated`)
- **Two required header parameters**: the configured signature header
  (`api.webhooks.signature_header`, default `X-Webhook-Signature`, format `t=...,v1=...`) and
  `X-Webhook-Timestamp` (Unix seconds)
- **A request body schema** composing the standard `WebhookEnvelope` (`id`, `event`, `created_at`,
  `data`) with the payload schema under `data`; without `payload_schema`, the bare envelope
- **Documented response codes**: `2XX` (acknowledged) and `410` (subscriber gone)

```json
{
  "webhooks": {
    "user.created": {
      "post": {
        "operationId": "onUserCreated",
        "parameters": [
          { "name": "X-Webhook-Signature", "in": "header", "required": true },
          { "name": "X-Webhook-Timestamp", "in": "header", "required": true }
        ],
        "requestBody": {
          "content": {
            "application/json": {
              "schema": {
                "allOf": [
                  { "$ref": "#/components/schemas/WebhookEnvelope" },
                  { "properties": { "data": { "$ref": "#/components/schemas/User" } } }
                ]
              }
            }
          }
        }
      }
    }
  }
}
```

Off-the-shelf generators (`openapi-typescript`, `openapi-generator-cli`) then produce handler types
for the declared events.

## Related

- `src/Api/Webhooks/WebhookDispatcher.php` — creates and queues deliveries
- `src/Api/Webhooks/Jobs/DeliverWebhookJob.php` — delivery, destination checks, retries
- `src/Api/Webhooks/Listeners/WebhookEventListener.php` — event → webhook bridge
- `src/Api/Webhooks/WebhookSignature.php` — signing and verification
- `src/Http/Services/WebhookDeliveryService.php` — ad-hoc signed deliveries
- `src/Support/Documentation/WebhookDocsBuilder.php` — emits the OpenAPI `webhooks` block
- `routes/webhooks.php`, `migrations/webhooks/`
