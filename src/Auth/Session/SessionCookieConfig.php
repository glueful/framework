<?php

declare(strict_types=1);

namespace Glueful\Auth\Session;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Typed view of `auth.session_cookie.*`.
 *
 * One place parses this configuration, so the middleware, the issuer and the session
 * endpoints cannot drift on cookie names or paths — a mismatch there does not fail loudly,
 * it silently leaves a credential in the browser or fails to send one.
 */
final class SessionCookieConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $accessName,
        public readonly string $refreshName,
        public readonly int $refreshTtl,
        public readonly string $path,
        public readonly string $refreshPath,
        public readonly ?string $domain,
        public readonly bool $secure,
        public readonly string $sameSite,
    ) {
    }

    public static function fromContext(ApplicationContext $context): self
    {
        $configured = config($context, 'auth.session_cookie', []);
        /** @var array<string,mixed> $raw */
        $raw = is_array($configured) ? $configured : [];

        $domain = $raw['domain'] ?? null;
        $domain = is_string($domain) && $domain !== '' ? $domain : null;

        return new self(
            enabled: (bool) ($raw['enabled'] ?? false),
            accessName: (string) ($raw['access_name'] ?? 'gf_session'),
            refreshName: (string) ($raw['refresh_name'] ?? 'gf_refresh'),
            refreshTtl: (int) ($raw['refresh_ttl'] ?? 2592000),
            path: (string) ($raw['path'] ?? '/'),
            refreshPath: (string) ($raw['refresh_path'] ?? '/auth/session'),
            domain: $domain,
            secure: (bool) ($raw['secure'] ?? true),
            sameSite: (string) ($raw['same_site'] ?? Cookie::SAMESITE_LAX),
        );
    }
}
