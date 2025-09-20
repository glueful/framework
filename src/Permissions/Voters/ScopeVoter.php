<?php

declare(strict_types=1);

namespace Glueful\Permissions\Voters;

use Glueful\Permissions\{VoterInterface, Vote, Context};
use Glueful\Auth\UserIdentity;

final class ScopeVoter implements VoterInterface
{
    public function supports(string $permission, mixed $resource, Context $ctx): bool
    {
        return isset($ctx->jwtClaims['scope']);
    }

    public function vote(UserIdentity $user, string $permission, mixed $resource, Context $ctx): Vote
    {
        $scope = $ctx->jwtClaims['scope'];
        $scopes = is_string($scope) ? preg_split('/\s+/', trim($scope)) : (array) $scope;
        // Simple mapping: permission equals scope or shares same name
        if (in_array($permission, $scopes, true)) {
            return new Vote(Vote::GRANT);
        }
        return new Vote(Vote::ABSTAIN);
    }

    public function priority(): int
    {
        return 20;
    }
}
