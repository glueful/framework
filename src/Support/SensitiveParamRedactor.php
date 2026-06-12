<?php

declare(strict_types=1);

namespace Glueful\Support;

/**
 * Shared name-pattern redaction for URIs, query strings, and parameter arrays
 * before they are logged or reported.
 *
 * This is the single source of truth for what counts as a sensitive parameter
 * name. Request/response logging, exception reporting, authentication access
 * logs, and security-event listeners all redact through here so the pattern
 * lists cannot drift apart again.
 */
final class SensitiveParamRedactor
{
    public const REDACTED = '[REDACTED]';

    /**
     * Exact (case-insensitive) parameter names that are always redacted.
     *
     * @var array<string>
     */
    private const SENSITIVE_FIELDS = [
        'password',
        'secret',
        'token',
        'api_key',
        'access_token',
        'refresh_token',
        'client_secret',
        'private_key',
        'credit_card',
        'ssn',
        'social_security_number',
        'cvv',
        'cvc',
        'pin',
        'otp',
        'code',
        'auth',
        'authorization',
        'authorization_code',
    ];

    /**
     * Substrings that mark a parameter name as sensitive wherever they appear
     * (access_token, api_key, x-signature, new_password, ...).
     *
     * @var array<string>
     */
    private const SENSITIVE_SUBSTRINGS = [
        'token',
        'key',
        'secret',
        'signature',
        'password',
    ];

    private function __construct()
    {
    }

    /**
     * @param array<string> $extraExactNames Additional exact names to redact
     */
    public static function isSensitiveName(string $name, array $extraExactNames = []): bool
    {
        $normalized = strtolower($name);

        if (
            in_array($normalized, self::SENSITIVE_FIELDS, true)
            || in_array($normalized, $extraExactNames, true)
        ) {
            return true;
        }

        foreach (self::SENSITIVE_SUBSTRINGS as $substring) {
            if (str_contains($normalized, $substring)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively redact values whose keys have sensitive names. A sensitive
     * key redacts its entire value, including array subtrees.
     *
     * @param array<mixed> $data
     * @param array<string> $extraExactNames
     */
    public static function sanitizeArray(array &$data, array $extraExactNames = []): void
    {
        foreach ($data as $key => &$value) {
            if (self::isSensitiveName((string) $key, $extraExactNames)) {
                $value = self::REDACTED;
            } elseif (is_array($value)) {
                self::sanitizeArray($value, $extraExactNames);
            }
        }
        unset($value);
    }

    /**
     * Redact sensitive parameters in a raw query string. Returns the input
     * unchanged when it does not parse into named parameters.
     */
    public static function sanitizeQueryString(?string $query): ?string
    {
        if ($query === null || $query === '') {
            return $query;
        }

        $params = [];
        parse_str($query, $params);

        if ($params === []) {
            return $query;
        }

        self::sanitizeArray($params);

        return http_build_query($params);
    }

    /**
     * Redact sensitive query parameters in a URL or request URI. Userinfo and
     * fragments are dropped; an unparseable URL is fully redacted.
     */
    public static function sanitizeUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return self::REDACTED;
        }

        $sanitized = '';
        if (isset($parts['scheme'])) {
            $sanitized .= $parts['scheme'] . '://';
        }

        if (isset($parts['host'])) {
            $sanitized .= $parts['host'];
        }

        if (isset($parts['port'])) {
            $sanitized .= ':' . $parts['port'];
        }

        $sanitized .= $parts['path'] ?? '';

        $query = self::sanitizeQueryString($parts['query'] ?? null);
        if ($query !== null && $query !== '') {
            $sanitized .= '?' . $query;
        }

        return $sanitized !== '' ? $sanitized : $url;
    }
}
