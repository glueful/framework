<?php

declare(strict_types=1);

namespace Glueful\Permissions\Voters;

use Glueful\Permissions\{VoterInterface, Vote, Context};
use Glueful\Auth\UserIdentity;

final class RoleVoter implements VoterInterface
{
    /** @var array<string, string[]> role => permissions */
    private array $map;

    /** @param array<string, string[]> $rolePermissionMap */
    public function __construct(array $rolePermissionMap)
    {
        $this->map = $rolePermissionMap;
    }

    public function supports(string $permission, mixed $resource, Context $ctx): bool
    {
        return true;
    }

    public function vote(UserIdentity $user, string $permission, mixed $resource, Context $ctx): Vote
    {
        foreach ($user->roles() as $role) {
            $perms = $this->map[$role] ?? [];
            if (in_array('*', $perms, true)) {
                return new Vote(Vote::GRANT);
            }
            // dot-prefix wildcards like "post.*"
            $dot = strpos($permission, '.') !== false ? substr($permission, 0, strpos($permission, '.')) . '.*' : null;
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
        return 10;
    }
}
