<?php

declare(strict_types=1);

namespace Glueful\Permissions\Voters;

use Glueful\Permissions\{VoterInterface, Vote, Context, PolicyRegistry};
use Glueful\Auth\UserIdentity;

final class PolicyVoter implements VoterInterface
{
    public function __construct(private PolicyRegistry $policies)
    {
    }

    public function supports(string $permission, mixed $resource, Context $ctx): bool
    {
        if ($resource === null) {
            return false;
        }
        $key = $ctx->extra['resource_slug'] ?? (is_object($resource) ? $resource::class : (string)$resource);
        return (bool) $this->policies->get($key);
    }

    public function vote(UserIdentity $user, string $permission, mixed $resource, Context $ctx): Vote
    {
        $key = $ctx->extra['resource_slug'] ?? (is_object($resource) ? $resource::class : (string)$resource);
        $policy = $this->policies->get($key);
        if ($policy === null) {
            return new Vote(Vote::ABSTAIN);
        }

        // Map permission like "post.update" => "update", or use the whole permission if no dot.
        $ability = (($pos = strrpos($permission, '.')) !== false) ? substr($permission, $pos + 1) : $permission;

        if (!method_exists($policy, $ability)) {
            return new Vote(Vote::ABSTAIN);
        }

        /** @var callable $method */
        $method = [$policy, $ability];
        $result = $method($user, $resource, $ctx); // true|false|null
        return match ($result) {
            true  => new Vote(Vote::GRANT),
            false => new Vote(Vote::DENY),
            default => new Vote(Vote::ABSTAIN),
        };
    }

    public function priority(): int
    {
        // Run after SuperRole (0) and before Role (10)
        return 5;
    }
}
