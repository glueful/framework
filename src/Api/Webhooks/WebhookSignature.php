<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks;

/**
 * HMAC-SHA256 signature generation and verification for webhooks
 *
 * Uses Stripe-style signature format: t=timestamp,v1=signature
 * This allows for timestamp validation to prevent replay attacks
 * and supports multiple signature versions for future compatibility.
 */
class WebhookSignature
{
    private const ALGORITHM = 'sha256';

    /**
     * Generate signature for payload
     *
     * @param string $payload JSON payload
     * @param string $secret Webhook secret
     * @param int $timestamp Unix timestamp
     * @return string Signature header value (t=timestamp,v1=signature)
     */
    public static function generate(string $payload, string $secret, int $timestamp): string
    {
        $signedPayload = "{$timestamp}.{$payload}";
        $signature = hash_hmac(self::ALGORITHM, $signedPayload, $secret);

        return "t={$timestamp},v1={$signature}";
    }

    /**
     * Verify signature from request
     *
     * @param string $payload Request body
     * @param string $signatureHeader X-Webhook-Signature header value
     * @param string $secret Webhook secret
     * @param int|null $tolerance Timestamp tolerance in seconds (default 5 minutes, null to skip)
     * @return bool True if valid
     */
    public static function verify(
        string $payload,
        string $signatureHeader,
        string $secret,
        ?int $tolerance = 300
    ): bool {
        $parts = self::parse($signatureHeader);

        if ($parts === null) {
            return false;
        }

        ['timestamp' => $timestamp, 'signatures' => $signatures] = $parts;

        // Check timestamp tolerance (replay attack prevention) if tolerance is set
        if ($tolerance !== null && abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        // Generate expected signature
        $signedPayload = "{$timestamp}.{$payload}";
        $expected = hash_hmac(self::ALGORITHM, $signedPayload, $secret);

        // Check if any signature matches (timing-safe comparison)
        foreach ($signatures as $version => $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse signature header into components
     *
     * @param string $header Signature header value
     * @return array{timestamp: int, signatures: array<string, string>}|null
     */
    public static function parse(string $header): ?array
    {
        $items = explode(',', $header);
        $timestamp = null;
        $signatures = [];

        foreach ($items as $item) {
            $parts = explode('=', trim($item), 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$key, $value] = $parts;

            if ($key === 't') {
                $timestamp = (int) $value;
            } elseif (str_starts_with($key, 'v')) {
                $signatures[$key] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return null;
        }

        return ['timestamp' => $timestamp, 'signatures' => $signatures];
    }

    /**
     * Get the expected signature header name
     */
    public static function getHeaderName(): string
    {
        return 'X-Webhook-Signature';
    }

    /**
     * Get the algorithm used for signing
     */
    public static function getAlgorithm(): string
    {
        return self::ALGORITHM;
    }
}
