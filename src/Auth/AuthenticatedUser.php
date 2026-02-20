<?php

declare(strict_types=1);

namespace Glueful\Auth;

/**
 * Minimal runtime identity used by framework auth/permission checks.
 */
final class AuthenticatedUser
{
    /**
     * @param list<string> $roles
     * @param array<string, list<string>>|list<string> $permissions
     */
    public function __construct(
        public readonly string $uuid,
        public readonly ?string $sessionUuid = null,
        public readonly ?string $provider = null,
        public readonly ?string $username = null,
        public readonly ?string $email = null,
        public readonly array $roles = [],
        public readonly array $permissions = []
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'session_uuid' => $this->sessionUuid,
            'provider' => $this->provider,
            'username' => $this->username,
            'email' => $this->email,
            'roles' => $this->roles,
            'permissions' => $this->permissions,
        ];
    }
}
