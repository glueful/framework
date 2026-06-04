<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Auth;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\UserIdentity;

/**
 * In-memory {@see UserProviderInterface} for framework tests. Lets the seam be exercised without
 * depending on any concrete user store (e.g. glueful/users) — the real provider's behaviour is
 * covered by that extension's own suite. Passwords are compared as plain fixtures; this is a test
 * double, not a credential store.
 */
final class InMemoryUserProvider implements UserProviderInterface
{
    /** @var array<string, UserIdentity> */
    private array $byUuid = [];
    /** @var array<string, array{password: string, identity: UserIdentity}> */
    private array $byLogin = [];

    /**
     * Register an identity, optionally with a password and one or more login identifiers
     * (email/username) that resolve to it.
     */
    public function add(UserIdentity $identity, string $password = '', string ...$logins): self
    {
        $this->byUuid[$identity->uuid()] = $identity;
        foreach ($logins as $login) {
            $this->byLogin[$login] = ['password' => $password, 'identity' => $identity];
        }
        return $this;
    }

    public function findByUuid(string $uuid): ?UserIdentity
    {
        return $this->byUuid[$uuid] ?? null;
    }

    public function findByLogin(string $identifier): ?UserIdentity
    {
        return $this->byLogin[$identifier]['identity'] ?? null;
    }

    public function verifyCredentials(string $identifier, string $password): ?UserIdentity
    {
        $entry = $this->byLogin[$identifier] ?? null;
        if ($entry === null) {
            return null;
        }
        return hash_equals($entry['password'], $password) ? $entry['identity'] : null;
    }
}
