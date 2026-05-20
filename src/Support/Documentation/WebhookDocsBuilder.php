<?php

declare(strict_types=1);

namespace Glueful\Support\Documentation;

/**
 * Builds the OpenAPI 3.1 webhooks section from configured events.
 *
 * Outbound HTTP webhooks dispatched by `WebhookDeliveryService` are
 * surfaced here so SDK generators (e.g. openapi-typescript) can
 * scaffold handler types automatically.
 */
final class WebhookDocsBuilder
{
    /**
     * @param  array<string, array{summary?: string, payload_schema?: string}> $config
     * @return array<string, mixed>
     */
    public function build(array $config): array
    {
        $webhooks = [];
        foreach ($config as $event => $meta) {
            $payloadRef = isset($meta['payload_schema']) && is_string($meta['payload_schema'])
                ? '#/components/schemas/' . $meta['payload_schema']
                : null;

            $schema = $payloadRef !== null
                ? [
                    'allOf' => [
                        ['$ref' => '#/components/schemas/WebhookEnvelope'],
                        ['properties' => ['data' => ['$ref' => $payloadRef]]],
                    ],
                ]
                : ['$ref' => '#/components/schemas/WebhookEnvelope'];

            $webhooks[$event] = [
                'post' => [
                    'summary' => $meta['summary'] ?? "Webhook for {$event}",
                    'operationId' => $this->operationIdFor($event),
                    'parameters' => $this->signatureHeaders(),
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => ['schema' => $schema],
                        ],
                    ],
                    'responses' => [
                        '2XX' => ['description' => 'Acknowledged'],
                        '410' => ['description' => 'Subscriber gone — stop delivering'],
                    ],
                ],
            ];
        }
        return $webhooks;
    }

    private function operationIdFor(string $event): string
    {
        $parts = preg_split('/[._\-]+/', $event) ?: [];
        $parts = array_map(static fn (string $p): string => ucfirst(strtolower($p)), $parts);
        return 'on' . implode('', $parts);
    }

    /**
     * Build the standard webhook authentication headers documented for every
     * webhook delivery sent by WebhookDeliveryService.
     *
     * @return list<array<string, mixed>>
     */
    private function signatureHeaders(): array
    {
        return [
            [
                'name' => 'X-Glueful-Signature',
                'in' => 'header',
                'required' => true,
                'description' => 'HMAC-SHA256 signature of the request body in '
                    . 'Stripe-compatible format `t={timestamp},v1={hex_signature}`. '
                    . 'Verify with the subscription secret before processing.',
                'schema' => ['type' => 'string'],
            ],
            [
                'name' => 'X-Glueful-Timestamp',
                'in' => 'header',
                'required' => true,
                'description' => 'Unix timestamp (seconds) when the delivery was sent. '
                    . 'Match against the timestamp embedded in X-Glueful-Signature to detect replays.',
                'schema' => ['type' => 'integer'],
            ],
        ];
    }
}
