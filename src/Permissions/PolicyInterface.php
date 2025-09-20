<?php

declare(strict_types=1);

namespace Glueful\Permissions;

use Glueful\Auth\UserIdentity;

interface PolicyInterface
{
    public function view(UserIdentity $user, mixed $resource, Context $ctx): ?bool;
    public function create(UserIdentity $user, mixed $resource, Context $ctx): ?bool;
    public function update(UserIdentity $user, mixed $resource, Context $ctx): ?bool;
    public function delete(UserIdentity $user, mixed $resource, Context $ctx): ?bool;
    // For arbitrary abilities, Gate will try method name that matches the ability
}
