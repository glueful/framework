<?php

declare(strict_types=1);

namespace Glueful\Auth;

/**
 * JWT (JSON Web Token) Service
 *
 * Handles JWT token generation, validation, and management.
 * Provides secure token-based authentication for the API.
 */
class JWTService
{
    /** @var string JWT secret key */
    private static string $key;

    /** @var string Default hashing algorithm */
    private static string $algorithm = 'HS256';

    /**
     * Initialize JWT service
     *
     * Sets up JWT secret key from configuration.
     *
     * @throws \RuntimeException If JWT key is not configured
     */
    private static function initialize(): void
    {
        if (!isset(self::$key)) {
            $configured = config('session.jwt_key');
            if (!is_string($configured) || $configured === '') {
                throw new \RuntimeException('JWT key not configured');
            }
            self::$key = $configured;
        }
    }

    /**
     * Generate new JWT token
     *
     * Creates a signed JWT token with provided payload and expiration.
     *
     * @param array<string, mixed> $payload Token payload data
     * @param int $expiration Token lifetime in seconds
     * @return string Generated JWT token
     */
    public static function generate(array $payload, int $expiration = 900): string
    {
        self::initialize();

        // Lock to HS256 to match signing logic
        $header = [
            'typ' => 'JWT',
            'alg' => self::$algorithm
        ];

        $payload['iat'] = time();  // Issued at
        $payload['exp'] = time() + $expiration;  // Expiration
        $payload['jti'] = bin2hex(random_bytes(16));  // JWT ID

        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));

        $signature = hash_hmac(
            'sha256',
            $headerEncoded . '.' . $payloadEncoded,
            self::$key,
            true
        );

        $signatureEncoded = self::base64UrlEncode($signature);

        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    /**
     * Decode JWT token
     *
     * Verifies and decodes JWT token into payload data.
     *
     * @param string $token JWT token to decode
     * @return array<string, mixed>|null Decoded payload or null if invalid
     */
    public static function decode(string $token): ?array
    {
        self::initialize();

        // Revocation is enforced via DB-backed checks outside this class

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        // Verify signature
        $signature = hash_hmac(
            'sha256',
            $headerEncoded . '.' . $payloadEncoded,
            self::$key,
            true
        );

        $signatureProvided = self::base64UrlDecode($signatureEncoded);
        if (!hash_equals($signature, $signatureProvided)) {
            return null;
        }

        // Decode payload
        $payload = json_decode(self::base64UrlDecode($payloadEncoded), true);
        if (!is_array($payload)) {
            return null;
        }

        // Verify expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    /**
     * Verify JWT token
     *
     * Checks if token is valid and not expired.
     *
     * @param string $token JWT token to verify
     * @return bool True if token is valid
     */
    public static function verify(string $token): bool
    {
        return self::decode($token) !== null;
    }

    /**
     * Base64URL encode
     *
     * Encodes data for use in URL-safe JWT.
     *
     * @param string $data Data to encode
     * @return string Base64URL encoded string
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64URL decode
     *
     * Decodes Base64URL encoded JWT components.
     *
     * @param string $data Data to decode
     * @return string Decoded string
     */
    private static function base64UrlDecode(string $data): string
    {
        $b64 = strtr($data, '-_', '+/');
        $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
        $decoded = base64_decode($b64, true);
        return $decoded === false ? '' : $decoded;
    }

    /**
     * Extract payload without signature validation
     *
     * Extracts and decodes the payload portion of a JWT token without
     * validating the signature. This is useful for getting the subject (sub)
     * or other non-secure information for logging purposes.
     *
     * NOTE: This method should NEVER be used for authentication or authorization
     * as it does not verify the token's authenticity.
     *
     * @param string $token JWT token
     * @return array<string, mixed>|null Decoded payload or null if malformed
     */
    public static function getPayloadWithoutValidation(string $token): ?array
    {
        try {
            // Split the token into parts
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }
            // Decode the payload (middle part)
            $payload = json_decode(self::base64UrlDecode($parts[1]), true);
            return is_array($payload) ? $payload : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Extract claims from token
     *
     * Gets payload claims excluding JWT metadata.
     *
     * @param string $token JWT token
     * @return array<string, mixed>|null Claims or null if invalid
     */
    public static function extractClaims(string $token): ?array
    {
        $payload = self::decode($token);
        return is_array($payload) ? array_diff_key($payload, array_flip(['iat', 'exp', 'jti'])) : null;
    }

    /**
     * Check if token is expired
     *
     * Verifies token expiration timestamp.
     *
     * @param string $token JWT token
     * @return bool True if token is expired
     */
    public static function isExpired(string $token): bool
    {
        $payload = self::decode($token);
        return $payload === null || (isset($payload['exp']) && $payload['exp'] < time());
    }

    /**
     * Sign claims with RS256 (private key) and return a JWT.
     * Does not verify key validity beyond OpenSSL parsing.
     *
     * @param array<string, mixed> $claims
     * @throws \RuntimeException on signing failure
     */
    public static function signRS256(array $claims, string $privateKey): string
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('OpenSSL extension required for RS256 signing');
        }

        $header = ['typ' => 'JWT', 'alg' => 'RS256'];

        $headerEncoded = self::base64UrlEncode((string) json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadEncoded = self::base64UrlEncode((string) json_encode($claims, JSON_UNESCAPED_SLASHES));

        $signingInput = $headerEncoded . '.' . $payloadEncoded;

        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new \RuntimeException('Invalid private key for RS256 signing');
        }

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new \RuntimeException('Failed to sign JWT with RS256');
        }

        $signatureEncoded = self::base64UrlEncode($signature);
        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }
}
