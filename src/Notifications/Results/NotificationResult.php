<?php

declare(strict_types=1);

namespace Glueful\Notifications\Results;

/**
 * Result of a single channel delivery attempt.
 *
 * A richer alternative to the legacy `bool` return of {@see \Glueful\Notifications\Contracts\NotificationChannel::send()}.
 * Channels implementing {@see \Glueful\Notifications\Contracts\RichNotificationChannel} return one
 * of these so the dispatcher can record a provider message id, error code/message, retryability,
 * and latency. Legacy bool-returning channels are normalized via {@see self::fromBool()}.
 *
 * @package Glueful\Notifications\Results
 */
final readonly class NotificationResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public bool $success,
        public ?string $providerMessageId = null,
        public bool $retryable = true,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?int $latencyMs = null,
        public array $metadata = []
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function success(
        ?string $providerMessageId = null,
        ?int $latencyMs = null,
        array $metadata = []
    ): self {
        return new self(
            success: true,
            providerMessageId: $providerMessageId,
            latencyMs: $latencyMs,
            metadata: $metadata
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function failure(
        ?string $errorCode = null,
        ?string $errorMessage = null,
        bool $retryable = true,
        ?int $latencyMs = null,
        array $metadata = []
    ): self {
        return new self(
            success: false,
            retryable: $retryable,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            latencyMs: $latencyMs,
            metadata: $metadata
        );
    }

    /**
     * Adapt a legacy `send(): bool` return into a result.
     */
    public static function fromBool(bool $success): self
    {
        return $success
            ? new self(success: true)
            : new self(success: false, errorCode: 'send_failed');
    }
}
