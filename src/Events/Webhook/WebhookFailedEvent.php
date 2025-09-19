<?php

declare(strict_types=1);

namespace Glueful\Events\Webhook;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Webhook Failed Event
 *
 * Fired when a webhook delivery fails due to network errors, HTTP errors,
 * or other delivery issues. Allows applications to track failures and implement
 * fallback mechanisms.
 */
class WebhookFailedEvent extends BaseEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $url,
        public readonly array $payload,
        public readonly int $statusCode,
        public readonly string $reason,
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
    public function getReason(): string
    {
        return $this->reason;
    }
    public function getDurationMs(): float
    {
        return $this->durationMs;
    }

    public function isNetworkError(): bool
    {
        return $this->statusCode === 0;
    }
    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }
    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
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
