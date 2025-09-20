<?php

declare(strict_types=1);

namespace Glueful\Permissions;

use Glueful\Auth\UserIdentity;

interface VoterInterface
{
    public function supports(string $permission, mixed $resource, Context $ctx): bool;
    public function vote(UserIdentity $user, string $permission, mixed $resource, Context $ctx): Vote;
    public function priority(): int; // lower = earlier
}
