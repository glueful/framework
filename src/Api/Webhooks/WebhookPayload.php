<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks;

use Glueful\Api\Webhooks\Contracts\WebhookPayloadInterface;
use Glueful\Helpers\Utils;

/**
 * Builds standardized webhook payloads
 *
 * Creates consistent payload structure for all webhooks:
 * - Unique event ID
 * - Event name
 * - Timestamp
 * - Event data
 * - Metadata
 */
class WebhookPayload implements WebhookPayloadInterface
{
    /**
     * Build webhook payload
     *
     * @param string $event Event name
     * @param array<string, mixed> $data Event data
     * @param array<string, mixed> $metadata Optional metadata
     * @return array<string, mixed> Formatted payload
     */
    public function build(string $event, array $data, array $metadata = []): array
    {
        $payload = [
            'id' => 'wh_evt_' . Utils::generateNanoID(20),
            'event' => $event,
            'created_at' => date('c'), // ISO 8601 format
            'data' => $data,
        ];

        // Only include metadata if provided
        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        return $payload;
    }
}
