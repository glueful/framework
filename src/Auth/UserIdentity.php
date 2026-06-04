<?php

declare(strict_types=1);

namespace Glueful\Auth;

/**
 * Canonical authenticated identity plus its runtime claims (NOT a database user row).
 *
 * Final + immutable. All extensibility flows through the open claims bag and the with*()
 * builders — never through subclassing. Identity facts (uuid/email/username/status) are owned
 * by the UserProvider and are NOT overwritable via claims.
 */
final class UserIdentity
{
    /** @var array<string, mixed> Open claims bag; 'roles' and 'scopes' are well-known keys. */
    private array $claims;

    /**
     * @param array<int,string>   $roles      Folded into claims['roles'] (legacy positional arg).
     * @param array<int,string>   $scopes     Folded into claims['scopes'] (legacy positional arg).
     * @param array<string,mixed> $attributes Legacy attribute bag, folded into the claims bag.
     */
    public function __construct(
        private string $uuid,
        array $roles = [],
        array $scopes = [],
        array $attributes = [],
        private ?string $email = null,
        private ?string $username = null,
        private ?string $status = null,
        private ?string $sessionUuid = null,
        private ?string $provider = null,
    ) {
        $this->claims = $attributes;
        $this->claims['roles'] = array_values($roles);
        $this->claims['scopes'] = array_values($scopes);
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    /** Legacy alias for uuid(); kept so existing permission voters/policies keep working. */
    public function id(): string
    {
        return $this->uuid;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function username(): ?string
    {
        return $this->username;
    }

    public function status(): ?string
    {
        return $this->status;
    }

    public function sessionUuid(): ?string
    {
        return $this->sessionUuid;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }

    /** @return array<int,string> */
    public function roles(): array
    {
        $roles = $this->claims['roles'] ?? [];
        return is_array($roles) ? array_values($roles) : [];
    }

    /** @return array<int,string> */
    public function scopes(): array
    {
        $scopes = $this->claims['scopes'] ?? [];
        return is_array($scopes) ? array_values($scopes) : [];
    }

    /** @return array<string,mixed> */
    public function claims(): array
    {
        return $this->claims;
    }

    public function claim(string $key, mixed $default = null): mixed
    {
        return $this->claims[$key] ?? $default;
    }

    /** Legacy alias for claim(); kept for existing attribute-bag callers. */
    public function attr(string $key, mixed $default = null): mixed
    {
        return $this->claim($key, $default);
    }

    /**
     * Return a copy with $claims merged on top (key-level array_merge: new keys added,
     * existing keys REPLACED). Identity facts (uuid/email/username/status) are never touched.
     * NOTE: this is not union/additive at the key level — additive composition across claims
     * providers is enforced by IdentityResolver::mergeClaims(), not here.
     *
     * @param array<string,mixed> $claims
     */
    public function withClaims(array $claims): self
    {
        $clone = clone $this;
        $clone->claims = array_merge($this->claims, $claims);
        return $clone;
    }

    /** Return a copy with runtime session context attached. */
    public function withSession(string $sessionUuid, string $provider): self
    {
        $clone = clone $this;
        $clone->sessionUuid = $sessionUuid;
        $clone->provider = $provider;
        return $clone;
    }

    /** @return array<string,mixed> Stable shape for session/token persistence + logging. */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'session_uuid' => $this->sessionUuid,
            'provider' => $this->provider,
            'username' => $this->username,
            'email' => $this->email,
            'status' => $this->status,
            'roles' => $this->roles(),
            'permissions' => $this->claim('permissions', []),
            'claims' => $this->claims,
        ];
    }
}
