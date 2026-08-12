<?php

declare(strict_types=1);

namespace Glueful\Support;

/**
 * Shared redaction for URIs, paths, query strings, and parameter arrays before
 * they are logged or reported.
 *
 * This is the single source of truth for what counts as sensitive. Request/
 * response logging, exception reporting, authentication access logs, and
 * security-event listeners all redact through here so the pattern lists cannot
 * drift apart again.
 *
 * Two kinds of secret are covered:
 *  - sensitive parameter NAMES (compiled in below), redacted everywhere they
 *    appear as a key or query parameter;
 *  - sensitive request PATHS, which the host registers via
 *    {@see self::configureSensitivePaths()} because only the host knows which
 *    of its routes carry a credential in the path itself. The framework wires
 *    this from the `logging.sensitive_paths` config key at boot.
 *
 * Redaction happens at log-emission time only — no request is mutated, so
 * routing and handlers always see the original path.
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

    /**
     * Registered sensitive path templates, exactly as the host supplied them.
     *
     * @var list<string>
     */
    private static array $pathPatterns = [];

    /**
     * Compiled form of {@see self::$pathPatterns}: one list of segment tokens
     * per pattern. A token is either PLACEHOLDER (redact this segment),
     * WILDCARD (match any segment, keep it) or a lower-cased literal.
     *
     * @var list<list<string>>
     */
    private static array $compiledPathPatterns = [];

    private const PLACEHOLDER = "\0placeholder";
    private const WILDCARD = "\0wildcard";

    private function __construct()
    {
    }

    /**
     * Register the request paths that carry a credential in the path itself
     * (signed payment links, magic links, one-time downloads).
     *
     * A pattern is a route-shaped template matched segment by segment against
     * the start of the logged path:
     *
     *   '/checkout/pay/{token}'  redacts the third segment of /checkout/pay/<secret>
     *
     * A '{name}' segment is redacted; a bare '*' segment matches any single
     * segment and is kept (e.g. "/orders/[*]/pay/{token}", without the brackets).
     *
     * Literal segments are compared case-insensitively and after percent-decoding,
     * so an encoded path cannot slip past. Segments beyond the pattern are kept.
     * Registering nothing leaves every path byte-identical.
     *
     * This is log-emission-time only: no request is ever mutated, so routing,
     * signature verification and handlers still see the untouched path.
     *
     * @param array<int|string, mixed> $patterns
     */
    public static function configureSensitivePaths(array $patterns): void
    {
        $registered = [];
        $compiled = [];

        foreach ($patterns as $pattern) {
            if (!is_string($pattern)) {
                continue;
            }

            $trimmed = trim($pattern);
            if ($trimmed === '') {
                continue;
            }

            $tokens = self::compilePathPattern($trimmed);
            if ($tokens === []) {
                continue;
            }

            $registered[] = $trimmed;
            $compiled[] = $tokens;
        }

        self::$pathPatterns = $registered;
        self::$compiledPathPatterns = $compiled;
    }

    /**
     * The currently registered path templates (diagnostics and tests).
     *
     * @return list<string>
     */
    public static function sensitivePathPatterns(): array
    {
        return self::$pathPatterns;
    }

    /**
     * @return list<string> Segment tokens, empty when the pattern has no segments
     */
    private static function compilePathPattern(string $pattern): array
    {
        $tokens = [];

        foreach (explode('/', trim($pattern, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
                $tokens[] = self::PLACEHOLDER;
                continue;
            }

            if ($segment === '*') {
                $tokens[] = self::WILDCARD;
                continue;
            }

            $tokens[] = strtolower(rawurldecode($segment));
        }

        return $tokens;
    }

    /**
     * Redact credential-bearing segments of a URL path. Returns the path
     * unchanged when no pattern matches (and always when none are registered).
     */
    public static function sanitizePath(?string $path): ?string
    {
        if ($path === null || $path === '' || self::$compiledPathPatterns === []) {
            return $path;
        }

        $segments = explode('/', $path);
        // A path-absolute value explodes to a leading empty element; logical
        // segment 0 starts after it.
        $offset = $segments[0] === '' ? 1 : 0;
        $available = count($segments) - $offset;
        $changed = false;

        foreach (self::$compiledPathPatterns as $tokens) {
            if (count($tokens) > $available) {
                continue;
            }

            $redactAt = [];
            $matched = true;

            foreach ($tokens as $index => $token) {
                $segment = $segments[$offset + $index];

                if ($token === self::PLACEHOLDER) {
                    $redactAt[] = $offset + $index;
                    continue;
                }

                if ($token === self::WILDCARD) {
                    continue;
                }

                if (strtolower(rawurldecode($segment)) !== $token) {
                    $matched = false;
                    break;
                }
            }

            if (!$matched) {
                continue;
            }

            foreach ($redactAt as $position) {
                // Never invent a value for a trailing empty segment.
                if ($segments[$position] === '') {
                    continue;
                }
                $segments[$position] = self::REDACTED;
                $changed = true;
            }
        }

        return $changed ? implode('/', $segments) : $path;
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

        $sanitized .= self::sanitizePath($parts['path'] ?? null) ?? '';

        $query = self::sanitizeQueryString($parts['query'] ?? null);
        if ($query !== null && $query !== '') {
            $sanitized .= '?' . $query;
        }

        return $sanitized !== '' ? $sanitized : $url;
    }
}
