<?php

declare(strict_types=1);

namespace Glueful\Permissions\Voters;

use Glueful\Permissions\{VoterInterface, Vote, Context};
use Glueful\Auth\UserIdentity;

final class OwnershipVoter implements VoterInterface
{
    public function supports(string $permission, mixed $resource, Context $ctx): bool
    {
        // Convention: permissions ending with ".own" are ownership-scoped
        return str_ends_with($permission, '.own');
    }

    public function vote(UserIdentity $user, string $permission, mixed $resource, Context $ctx): Vote
    {
        $ownerId = $ctx->extra['ownerId'] ?? ($resource->authorId ?? ($resource->ownerId ?? null));
        if ($ownerId === null) {
            return new Vote(Vote::ABSTAIN);
        }
        return new Vote($ownerId == $user->id() ? Vote::GRANT : Vote::DENY);
    }

    public function priority(): int
    {
        return 30;
    }
}
