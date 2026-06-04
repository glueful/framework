<?php

declare(strict_types=1);

namespace Glueful\Auth\Contracts;

use Glueful\Auth\UserIdentity;

/**
 * Resolves identities and verifies credentials. Implemented by glueful/users (or any app user
 * store). Authentication-only: registration/provisioning/profile writes are NOT part of this
 * contract (core never provisions users).
 */
interface UserProviderInterface
{
    public function findByUuid(string $uuid): ?UserIdentity;

    /** Identifier-agnostic lookup (email/username/etc.) — rules live in the implementation. */
    public function findByLogin(string $identifier): ?UserIdentity;

    /** Returns the identity on success, null on invalid credentials. */
    public function verifyCredentials(string $identifier, string $password): ?UserIdentity;
}
