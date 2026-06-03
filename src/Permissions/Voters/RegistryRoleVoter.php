<?php

declare(strict_types=1);

namespace Glueful\Permissions\Voters;

use Glueful\Auth\UserIdentity;
use Glueful\Permissions\Catalog\PermissionRegistry;
use Glueful\Permissions\{Context, Vote, VoterInterface};

/**
 * Maps a user's already-known roles (from request identity / Context / JWT / config)
 * to permissions using the DECLARED role->permission map in the PermissionRegistry.
 *
 * The catalog defines what a role grants; it does NOT assign users to roles. If the
 * user has no roles, this voter abstains — it never fabricates membership.
 */
final class RegistryRoleVoter implements VoterInterface
{
    public function __construct(private readonly PermissionRegistry $registry)
    {
    }

    public function supports(string $permission, mixed $resource, Context $ctx): bool
    {
        return true;
    }

    public function vote(UserIdentity $user, string $permission, mixed $resource, Context $ctx): Vote
    {
        $map = $this->registry->rolePermissionMap();
        foreach ($user->roles() as $role) {
            $perms = $map[$role] ?? [];
            if (in_array('*', $perms, true)) {
                return new Vote(Vote::GRANT);
            }
            $dot = strpos($permission, '.') !== false
                ? substr($permission, 0, strpos($permission, '.')) . '.*'
                : null;
            if ($dot !== null && in_array($dot, $perms, true)) {
                return new Vote(Vote::GRANT);
            }
            if (in_array($permission, $perms, true)) {
                return new Vote(Vote::GRANT);
            }
        }
        return new Vote(Vote::ABSTAIN);
    }

    public function priority(): int
    {
        return 15; // after config RoleVoter (10), before ScopeVoter (20)
    }
}
