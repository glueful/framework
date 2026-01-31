<?php

declare(strict_types=1);

namespace Glueful\Support;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Signed URL generator and validator
 *
 * Generates time-limited URLs with HMAC signatures for secure
 * temporary access to protected resources.
 */
class SignedUrl
{
    private string $secretKey;
    private string $algorithm;

    public function __construct(?string $secretKey = null, string $algorithm = 'sha256')
    {
        $this->secretKey = $secretKey ?? $this->resolveSecretKey();
        $this->algorithm = $algorithm;
    }

    /**
     * Create a SignedUrl instance from application context.
     */
    public static function make(?ApplicationContext $context = null): self
    {
        $secretKey = null;
        if ($context !== null) {
            $secretKey = config($context, 'uploads.signed_urls.secret');
            if ($secretKey === null || $secretKey === '') {
                $secretKey = config($context, 'app.key');
            }
        }

        return new self(is_string($secretKey) ? $secretKey : null);
    }

    /**
     * Generate a signed URL for a given path.
     *
     * @param string $baseUrl The base URL (e.g., https://example.com/blobs/uuid)
     * @param int $expiresIn Seconds until expiration
     * @param array<string, string> $params Additional query parameters to include
     * @return string The signed URL
     */
    public function generate(string $baseUrl, int $expiresIn = 3600, array $params = []): string
    {
        $expires = time() + $expiresIn;
        $params['expires'] = (string) $expires;

        // Build the URL with params (excluding signature)
        $urlParts = parse_url($baseUrl);
        $path = $urlParts['path'] ?? '/';
        $existingQuery = [];
        if (isset($urlParts['query'])) {
            parse_str($urlParts['query'], $existingQuery);
        }

        $allParams = array_merge($existingQuery, $params);
        ksort($allParams); // Consistent ordering for signature

        // Generate signature
        $dataToSign = $path . '?' . http_build_query($allParams);
        $signature = $this->sign($dataToSign);
        $allParams['signature'] = $signature;

        // Build final URL
        $scheme = isset($urlParts['scheme']) ? $urlParts['scheme'] . '://' : '';
        $host = $urlParts['host'] ?? '';
        $port = isset($urlParts['port']) ? ':' . $urlParts['port'] : '';

        return $scheme . $host . $port . $path . '?' . http_build_query($allParams);
    }

    /**
     * Validate a signed URL.
     *
     * @param string $url The full URL to validate
     * @return bool True if valid and not expired
     */
    public function validate(string $url): bool
    {
        $urlParts = parse_url($url);
        if (!isset($urlParts['query'])) {
            return false;
        }

        parse_str($urlParts['query'], $params);

        // Check required parameters
        if (!isset($params['expires']) || !isset($params['signature'])) {
            return false;
        }

        // Check expiration
        $expires = (int) $params['expires'];
        if ($expires < time()) {
            return false;
        }

        // Extract and verify signature
        $providedSignature = $params['signature'];
        unset($params['signature']);
        ksort($params);

        $path = $urlParts['path'] ?? '/';
        $dataToSign = $path . '?' . http_build_query($params);
        $expectedSignature = $this->sign($dataToSign);

        return hash_equals($expectedSignature, $providedSignature);
    }

    /**
     * Validate signature from request parameters.
     *
     * @param string $path The request path
     * @param array<string, mixed> $params Query parameters including expires and signature
     * @return bool True if valid and not expired
     */
    public function validateParams(string $path, array $params): bool
    {
        if (!isset($params['expires']) || !isset($params['signature'])) {
            return false;
        }

        $expires = (int) $params['expires'];
        if ($expires < time()) {
            return false;
        }

        $providedSignature = (string) $params['signature'];
        unset($params['signature']);
        ksort($params);

        $dataToSign = $path . '?' . http_build_query($params);
        $expectedSignature = $this->sign($dataToSign);

        return hash_equals($expectedSignature, $providedSignature);
    }

    /**
     * Generate HMAC signature for data.
     */
    private function sign(string $data): string
    {
        return hash_hmac($this->algorithm, $data, $this->secretKey);
    }

    /**
     * Resolve secret key from environment or config.
     */
    private function resolveSecretKey(): string
    {
        $key = $_ENV['SIGNED_URL_SECRET'] ?? getenv('SIGNED_URL_SECRET');
        if (is_string($key) && $key !== '') {
            return $key;
        }

        $key = $_ENV['APP_KEY'] ?? getenv('APP_KEY');
        if (is_string($key) && $key !== '') {
            return $key;
        }

        // Fallback - not secure, should configure properly
        return 'glueful-default-signing-key';
    }
}
