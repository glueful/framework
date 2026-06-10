<?php

declare(strict_types=1);

namespace Glueful\Tests\Fixtures\ContainerPrecedence;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\UserIdentity;

final class FakeUserProvider implements UserProviderInterface
{
    public function findByUuid(string $uuid): ?UserIdentity
    {
        return null;
    }

    public function findByLogin(string $identifier): ?UserIdentity
    {
        return null;
    }

    public function verifyCredentials(string $identifier, string $password): ?UserIdentity
    {
        return null;
    }
}
