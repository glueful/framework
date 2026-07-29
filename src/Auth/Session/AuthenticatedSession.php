<?php

declare(strict_types=1);

namespace Glueful\Auth\Session;

/**
 * A completed authentication: the session a login produced.
 *
 * This type exists so that "we have a real session" is expressible in the type system.
 * Consumers that must never act on an unfinished login (cookie issuance above all) accept
 * THIS type rather than an array or a union, which makes the unsafe call unwritable instead
 * of merely discouraged.
 *
 * The original session array is retained verbatim so JSON responses can be shaped from an
 * unchanged payload — providers and login listeners add keys this object does not model.
 *
 * Only password login produces this type. Token and API-key credential exchange returns an
 * identity array with no tokens and is deliberately never modelled here.
 */
final class AuthenticatedSession
{
    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $raw
     */
    private function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly int $expiresIn,
        public readonly string $tokenType,
        public readonly array $user,
        private readonly array $raw,
    ) {
    }

    /** @param array<string,mixed> $session */
    public static function fromSessionArray(array $session): self
    {
        $accessToken = (string) ($session['access_token'] ?? '');
        $refreshToken = (string) ($session['refresh_token'] ?? '');
        if ($accessToken === '') {
            throw new \InvalidArgumentException('Session is missing an access token.');
        }
        if ($refreshToken === '') {
            throw new \InvalidArgumentException('Session is missing a refresh token.');
        }

        /** @var array<string,mixed> $user */
        $user = is_array($session['user'] ?? null) ? $session['user'] : [];

        return new self(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresIn: (int) ($session['expires_in'] ?? 0),
            tokenType: (string) ($session['token_type'] ?? 'Bearer'),
            user: $user,
            raw: $session,
        );
    }

    /** @return array<string,mixed> The session exactly as issued — no keys added or dropped. */
    public function toSessionArray(): array
    {
        return $this->raw;
    }
}
