<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Repository\UserRepository;

/**
 * Adapts UserRepository to UserProviderInterface. Created in core during the extraction
 * transition and bound as the default provider; in Phase 4 this exact class is `git mv`'d into
 * glueful/users (namespace changes; logic unchanged) — it is the canonical provider, not a
 * throwaway. NOTE: this lives at Glueful\Auth\UserProvider until the Phase-4 move.
 */
final class UserProvider implements UserProviderInterface
{
    private PasswordHasher $hasher;

    public function __construct(private UserRepository $users, ?PasswordHasher $hasher = null)
    {
        // Route password verification through the framework's hasher (centralized), not raw
        // password_verify(). Nullable default keeps it usable without the container.
        $this->hasher = $hasher ?? new PasswordHasher();
    }

    public function findByUuid(string $uuid): ?UserIdentity
    {
        return $this->toIdentity($this->users->findByUuid($uuid));
    }

    public function findByLogin(string $identifier): ?UserIdentity
    {
        return $this->toIdentity($this->lookup($identifier));
    }

    public function verifyCredentials(string $identifier, string $password): ?UserIdentity
    {
        $row = $this->lookup($identifier);
        // Guard for a real user row: findByUsername/findByEmail can return a validation-errors
        // array, so check for an actual uuid + password before trusting it.
        if (!is_array($row) || !isset($row['uuid'], $row['password'])) {
            return null;
        }
        if (!$this->hasher->verify($password, (string) $row['password'])) {
            return null;
        }
        return $this->toIdentity($row);
    }

    /** @return array<string,mixed>|null */
    private function lookup(string $identifier): ?array
    {
        $row = filter_var($identifier, FILTER_VALIDATE_EMAIL)
            ? $this->users->findByEmail($identifier)
            : $this->users->findByUsername($identifier);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed>|null $row */
    private function toIdentity(?array $row): ?UserIdentity
    {
        // Only a row with a real uuid is a user; a validation-errors array (no 'uuid') is not.
        if (!is_array($row) || !isset($row['uuid'])) {
            return null;
        }
        return new UserIdentity(
            uuid: (string) $row['uuid'],
            email: isset($row['email']) ? (string) $row['email'] : null,
            username: isset($row['username']) ? (string) $row['username'] : null,
            status: isset($row['status']) ? (string) $row['status'] : null,
        );
    }
}
