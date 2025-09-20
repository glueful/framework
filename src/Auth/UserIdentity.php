<?php

declare(strict_types=1);

namespace Glueful\Auth;

/**
 * Lightweight user identity for permission voters/policies.
 */
class UserIdentity
{
    private string $uuid;
    /** @var array<int, string> */
    private array $roles;
    /** @var array<int, string> */
    private array $scopes;
    /** @var array<string, mixed> */
    private array $attributes;

    /**
     * @param array<int,string> $roles
     * @param array<int,string> $scopes
     * @param array<string,mixed> $attributes
     */
    public function __construct(string $uuid, array $roles = [], array $scopes = [], array $attributes = [])
    {
        $this->uuid = $uuid;
        $this->roles = $roles;
        $this->scopes = $scopes;
        $this->attributes = $attributes;
    }

    public function id(): string
    {
        return $this->uuid;
    }

    /**
     * @return array<int, string>
     */
    public function roles(): array
    {
        return $this->roles;
    }

    /**
     * @return array<int, string>
     */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function attr(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
