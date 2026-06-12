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
     * @param array<string, mixed> $parameters
     */
    public function encodeScheduledParameters(string $handlerClass, array $parameters): string
    {
        if (!$this->shouldSign()) {
            return (string) json_encode($parameters);
        }

        $signedPayload = $this->sign([
            'handler_class' => $handlerClass,
            'parameters' => $parameters,
        ]);

        return (string) json_encode([
            self::SCHEDULED_PAYLOAD_KEY => true,
            'payload' => $signedPayload,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeScheduledParameters(string $handlerClass, ?string $encoded): array
    {
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
