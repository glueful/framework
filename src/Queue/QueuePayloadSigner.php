<?php

declare(strict_types=1);

namespace Glueful\Queue;

use Glueful\Bootstrap\ApplicationContext;

/**
 * HMAC signing for persisted queue and scheduler payloads.
 *
 * Queue backends are mutable storage. The handler class gate prevents arbitrary
 * constructor execution; this signer also detects tampering of a valid job
 * class, parameters, attempts, timeout, and other stored payload fields.
 */
final class QueuePayloadSigner
{
    private const SIGNATURE_KEY = '_glueful_signature';
    private const SIGNATURE_VERSION_KEY = '_glueful_signature_version';
    private const SIGNATURE_VERSION = 'v1';
    private const SCHEDULED_PAYLOAD_KEY = '_glueful_scheduled_payload';

    public function __construct(private ?ApplicationContext $context = null)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sign(array $payload): array
    {
        if (!$this->shouldSign()) {
            return $payload;
        }

        $unsigned = $this->withoutSignature($payload);
        $signingPayload = $unsigned;
        $signingPayload[self::SIGNATURE_VERSION_KEY] = self::SIGNATURE_VERSION;

        return $signingPayload + [
            self::SIGNATURE_KEY => $this->signatureFor($signingPayload),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function verify(array $payload): array
    {
        if (!$this->shouldSign()) {
            return $this->withoutSignature($payload);
        }

        $signature = $payload[self::SIGNATURE_KEY] ?? null;
        if (!is_string($signature) || $signature === '') {
            if ($this->requireSignedPayloads()) {
                throw new \RuntimeException('Queue payload is missing its HMAC signature');
            }

            return $this->withoutSignature($payload);
        }

        $unsigned = $this->withoutSignature($payload);
        $signingPayload = $unsigned;
        $signingPayload[self::SIGNATURE_VERSION_KEY] = $payload[self::SIGNATURE_VERSION_KEY] ?? self::SIGNATURE_VERSION;

        if (!hash_equals($this->signatureFor($signingPayload), $signature)) {
            throw new \RuntimeException('Queue payload signature mismatch');
        }

        return $unsigned;
    }

    /**
     * Encode scheduled-job parameters in a signed envelope.
     *
     * The signature also covers the handler class plus — when provided — the
     * row's name and cron schedule, so a tampered `scheduled_jobs` row cannot
     * swap the handler, clone a signed (handler, parameters) pair into another
     * row, or reschedule the job (e.g. to every minute) without breaking the
     * HMAC.
     *
     * @param array<string, mixed> $parameters
     */
    public function encodeScheduledParameters(
        string $handlerClass,
        array $parameters,
        ?string $name = null,
        ?string $schedule = null
    ): string {
        if (!$this->shouldSign()) {
            return (string) json_encode($parameters);
        }

        $payload = [
            'handler_class' => $handlerClass,
            'parameters' => $parameters,
        ];
        if ($name !== null) {
            $payload['name'] = $name;
        }
        if ($schedule !== null) {
            $payload['schedule'] = $schedule;
        }

        return (string) json_encode([
            self::SCHEDULED_PAYLOAD_KEY => true,
            'payload' => $this->sign($payload),
        ]);
    }

    /**
     * Decode and verify a scheduled-job parameter envelope.
     *
     * $name / $schedule are the values currently stored on the row; when the
     * signed envelope carries them they must match. Envelopes signed before
     * the binding existed lack the keys and skip the check — an attacker
     * cannot strip the keys from a bound envelope without invalidating the
     * signature.
     *
     * @return array<string, mixed>
     */
    public function decodeScheduledParameters(
        string $handlerClass,
        ?string $encoded,
        ?string $name = null,
        ?string $schedule = null
    ): array {
        $decoded = json_decode($encoded ?? '{}', true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        if (($decoded[self::SCHEDULED_PAYLOAD_KEY] ?? false) !== true) {
            if ($this->shouldSign() && $this->requireSignedPayloads()) {
                throw new \RuntimeException('Scheduled job payload is missing its HMAC signature');
            }

            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        $payload = $decoded['payload'] ?? null;
        if (!is_array($payload)) {
            throw new \RuntimeException('Scheduled job payload is malformed');
        }

        $verified = $this->verify($payload);
        if (($verified['handler_class'] ?? null) !== $handlerClass) {
            throw new \RuntimeException('Scheduled job handler signature mismatch');
        }

        if (array_key_exists('name', $verified) && $name !== null && $verified['name'] !== $name) {
            throw new \RuntimeException('Scheduled job name signature mismatch');
        }

        if (
            array_key_exists('schedule', $verified)
            && $schedule !== null
            && $verified['schedule'] !== $schedule
        ) {
            throw new \RuntimeException('Scheduled job schedule signature mismatch');
        }

        $parameters = $verified['parameters'] ?? [];
        if (!is_array($parameters)) {
            throw new \RuntimeException('Scheduled job parameters are malformed');
        }

        /** @var array<string, mixed> $parameters */
        return $parameters;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withoutSignature(array $payload): array
    {
        unset($payload[self::SIGNATURE_KEY]);
        unset($payload[self::SIGNATURE_VERSION_KEY]);
        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signatureFor(array $payload): string
    {
        $secret = $this->secret();
        if ($secret === null) {
            throw new \RuntimeException('Queue payload signing requires app.key or APP_KEY');
        }

        return hash_hmac('sha256', $this->canonicalJson($payload), $secret);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function canonicalJson(array $payload): string
    {
        $normalized = $this->normalize($payload);
        return (string) json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        if (!$this->isList($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        return array_is_list($value);
    }

    private function shouldSign(): bool
    {
        return $this->configBool('queue.security.payload_signing.enabled', true) && $this->secret() !== null;
    }

    private function requireSignedPayloads(): bool
    {
        return $this->configBool('queue.security.payload_signing.require_signed', true);
    }

    private function configBool(string $key, bool $default): bool
    {
        if ($this->context === null) {
            return $default;
        }

        return (bool) config($this->context, $key, $default);
    }

    private function secret(): ?string
    {
        $secret = null;
        if ($this->context !== null) {
            $configured = config($this->context, 'app.key');
            $secret = is_string($configured) && $configured !== '' ? $configured : null;
        }

        if ($secret === null) {
            $env = $_ENV['APP_KEY'] ?? getenv('APP_KEY');
            $secret = is_string($env) && $env !== '' ? $env : null;
        }

        if ($secret !== null && str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);
            return $decoded !== false ? $decoded : $secret;
        }

        return $secret;
    }
}
