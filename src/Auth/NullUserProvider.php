<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Auth\Contracts\UserProviderInterface;

/**
 * Fail-closed default user provider. Bound when no app/extension provider is registered:
 * every lookup and credential check returns null, so authentication cannot succeed.
 */
final class NullUserProvider implements UserProviderInterface
{
    public function findByUuid(string $uuid): ?UserIdentity
    {
        return null;
    }

    public function findByLogin(string $identifier): ?UserIdentity
    {
        return null;
    }

    public function verifyCredentials(string $identifier, string $password): ?UserIdentity
    {
        return null;
    }
}
