<?php

declare(strict_types=1);

namespace Glueful\Auth\Contracts;

use Glueful\Auth\UserIdentity;

/**
 * Decorates an authenticated identity with claims (roles/permissions/scopes) post-auth.
 * Implemented by authorization providers (e.g. glueful/aegis). MUST only ADD claims — the core
 * re-pins identity facts after each call, so enrich() can never change who the user is, only
 * what they can do.
 */
interface IdentityClaimsProviderInterface
{
    public function enrich(UserIdentity $identity): UserIdentity;
}
