# Webhooks

Glueful's `WebhookDeliveryService` sends outbound HTTP webhooks to subscribers when domain events occur. This guide explains how to declare those events so they appear in the generated OpenAPI 3.1 spec — letting SDK consumers scaffold handler types automatically.

## Declaring webhook events

Add entries under `documentation.webhooks` in `config/documentation.php`:

```php
'webhooks' => [
    'user.created' => [
        'summary' => 'A new user has been created.',
        'payload_schema' => 'User',  // References #/components/schemas/User
    ],
    'order.shipped' => [
        'summary' => 'An order has shipped.',
        'payload_schema' => 'Order',
    ],
    'heartbeat' => [
        'summary' => 'Periodic heartbeat with no specific payload.',
        // payload_schema omitted — uses the bare WebhookEnvelope
    ],
],
```

When `documentation.openapi_version` is `3.1.0` or higher (the default) and at least one webhook is declared, the generator emits a top-level `webhooks` object:

```json
{
  "webhooks": {
    "user.created": {
      "post": {
        "summary": "A new user has been created.",
        "operationId": "onUserCreated",
        "parameters": [
          { "name": "X-Glueful-Signature", "in": "header", "required": true, ... },
          { "name": "X-Glueful-Timestamp", "in": "header", "required": true, ... }
        ],
        "requestBody": {
          "required": true,
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
        },
        "responses": {
          "2XX": { "description": "Acknowledged" },
          "410": { "description": "Subscriber gone — stop delivering" }
        }
      }
    }
  }
}
```

## What gets generated

Each declared event produces:

- **An `operationId`** derived from the event name (`user.created` → `onUserCreated`)
- **Two required header parameters**: `X-Glueful-Signature` (HMAC-SHA256 in Stripe-style `t=...,v1=...` format) and `X-Glueful-Timestamp` (Unix seconds)
- **A request body schema** that composes the standard `WebhookEnvelope` (`id`, `event`, `created_at`, `data`) with the specified payload schema under `data`. If `payload_schema` is omitted, the body is just `WebhookEnvelope` with `data` as a generic object.
- **Documented response codes**: `2XX` (acknowledged) and `410` (subscriber gone — stop delivering)

## Verifying the signature

Subscribers should verify `X-Glueful-Signature` before processing. The signature format matches Stripe's convention:

```
t=1234567890,v1=hex_hmac_sha256(secret, "1234567890.{request_body}")
```

Glueful provides `Glueful\Api\Webhooks\WebhookSignature::verify()` for in-framework subscribers, but external consumers implement the standard HMAC-SHA256 verification with the subscription secret.

## SDK generation

Once your `openapi.json` includes the `webhooks` block, off-the-shelf generators handle the rest:

```bash
# Generate TypeScript types for webhook payloads
npx openapi-typescript openapi.json -o api.d.ts

# Generate Python handlers
openapi-generator-cli generate -i openapi.json -g python -o ./client
```

Generated handler types will reflect the declared event names, payload shapes, and signature header requirements — no manual maintenance needed.

## Related

- `src/Http/Services/WebhookDeliveryService.php` — the service that actually delivers webhooks
- `src/Api/Webhooks/WebhookSubscription.php` — model for subscriber configuration
- `src/Api/Webhooks/WebhookSignature.php` — signature generation and verification helpers
- `src/Support/Documentation/WebhookDocsBuilder.php` — emits the OpenAPI `webhooks` block
