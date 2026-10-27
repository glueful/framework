<?php

declare(strict_types=1);

namespace Glueful\Api\Webhooks;

use Glueful\Bootstrap\ApplicationContext;

/**
 * HMAC signature generation and verification for webhooks — the one signing scheme every
 * outbound delivery uses (queued subscription deliveries, test sends, WebhookDeliveryService).
 *
 * Uses Stripe-style signature format: t=timestamp,v1=signature, where signature is the hex
 * HMAC of "<timestamp>.<raw body>". This allows for timestamp validation to prevent replay
 * attacks and supports multiple signature versions for future compatibility.
 *
 * The header name and HMAC algorithm come from `api.webhooks.signature_header` and
 * `api.webhooks.signature_algorithm` (defaults: X-Webhook-Signature, sha256).
 */
class WebhookSignature
{
    private const ALGORITHM = 'sha256';

    public const DEFAULT_HEADER = 'X-Webhook-Signature';

    public const TIMESTAMP_HEADER = 'X-Webhook-Timestamp';

    /**
     * Generate signature for payload
     *
     * @param string $payload JSON payload
     * @param string $secret Webhook secret
     * @param int $timestamp Unix timestamp
     * @param string $algorithm HMAC algorithm (any hash_hmac_algos() entry)
     * @return string Signature header value (t=timestamp,v1=signature)
     * @throws \InvalidArgumentException When the algorithm is not supported
     */
    public static function generate(
        string $payload,
        string $secret,
        int $timestamp,
        string $algorithm = self::ALGORITHM
    ): string {
        $signature = hash_hmac(self::assertAlgorithm($algorithm), "{$timestamp}.{$payload}", $secret);

        return "t={$timestamp},v1={$signature}";
    }

    /**
     * Verify signature from request
     *
     * @param string $payload Request body
     * @param string $signatureHeader Signature header value
     * @param string $secret Webhook secret
     * @param int|null $tolerance Timestamp tolerance in seconds (default 5 minutes, null to skip)
     * @param string $algorithm HMAC algorithm the sender used
     * @return bool True if valid
     */
    public static function verify(
        string $payload,
        string $signatureHeader,
        string $secret,
        ?int $tolerance = 300,
        string $algorithm = self::ALGORITHM
    ): bool {
        if (!in_array($algorithm, hash_hmac_algos(), true)) {
            return false;
        }

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
        $expected = hash_hmac($algorithm, $signedPayload, $secret);

        // Check if any signature matches (timing-safe comparison)
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The signing headers for one outbound request: the configured signature header and
     * X-Webhook-Timestamp. Sign the exact bytes you send as the body.
     *
     * @return array<string, string>
     */
    public static function headers(
        string $payload,
        string $secret,
        ?ApplicationContext $context = null,
        ?int $timestamp = null
    ): array {
        $timestamp ??= time();

        $signature = self::generate($payload, $secret, $timestamp, self::getAlgorithm($context));

        return [
            self::getHeaderName($context) => $signature,
            self::TIMESTAMP_HEADER => (string) $timestamp,
        ];
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
     * The signature header name: `api.webhooks.signature_header`, else X-Webhook-Signature.
     */
    public static function getHeaderName(?ApplicationContext $context = null): string
    {
        if ($context === null) {
            return self::DEFAULT_HEADER;
        }

        $header = config($context, 'api.webhooks.signature_header', self::DEFAULT_HEADER);

        return is_string($header) && trim($header) !== '' ? trim($header) : self::DEFAULT_HEADER;
    }

    /**
     * The HMAC algorithm: `api.webhooks.signature_algorithm`, else sha256.
     */
    public static function getAlgorithm(?ApplicationContext $context = null): string
    {
        if ($context === null) {
            return self::ALGORITHM;
        }

        $algorithm = config($context, 'api.webhooks.signature_algorithm', self::ALGORITHM);

        return is_string($algorithm) && trim($algorithm) !== '' ? strtolower(trim($algorithm)) : self::ALGORITHM;
    }

    private static function assertAlgorithm(string $algorithm): string
    {
        if (!in_array($algorithm, hash_hmac_algos(), true)) {
            throw new \InvalidArgumentException("Unsupported webhook signature algorithm: {$algorithm}");
        }

        return $algorithm;
    }
}
