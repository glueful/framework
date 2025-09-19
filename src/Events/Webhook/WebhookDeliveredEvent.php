<?php

declare(strict_types=1);

namespace Glueful\Events\Webhook;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Webhook Delivered Event
 *
 * Fired when a webhook is successfully delivered to a remote endpoint.
 * Allows applications to track webhook delivery success and metrics.
 */
class WebhookDeliveredEvent extends BaseEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $url,
        public readonly array $payload,
        public readonly int $statusCode,
        public readonly float $durationMs
    ) {
        parent::__construct();
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    public function getDurationMs(): float
    {
        return $this->durationMs;
    }

    public function getHost(): ?string
    {
        $parsed = parse_url($this->url);
        return $parsed['host'] ?? null;
    }

    public function getPayloadSize(): int
    {
        return strlen(json_encode($this->payload));
    }
}
