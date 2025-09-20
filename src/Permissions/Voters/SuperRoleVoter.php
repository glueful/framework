<?php

declare(strict_types=1);

namespace Glueful\Permissions\Voters;

use Glueful\Permissions\{VoterInterface, Vote, Context};
use Glueful\Auth\UserIdentity;

final class SuperRoleVoter implements VoterInterface
{
    /** @param string[] $superRoles */
    public function __construct(private array $superRoles = [])
    {
    }

    public function supports(string $permission, mixed $resource, Context $ctx): bool
    {
        return count($this->superRoles) > 0;
    }

    public function vote(UserIdentity $user, string $permission, mixed $resource, Context $ctx): Vote
    {
        foreach ($user->roles() as $r) {
            if (in_array($r, $this->superRoles, true)) {
                return new Vote(Vote::GRANT);
            }
        }
        return new Vote(Vote::ABSTAIN);
    }

    public function priority(): int
    {
        return 0;
    }
}
