<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Http;

use Glueful\Auth\UserIdentity;
use Glueful\Http\RequestUserContext;
use PHPUnit\Framework\TestCase;

final class RequestUserContextIdentityTest extends TestCase
{
    public function test_get_user_returns_user_identity_type(): void
    {
        // A nullable named type stringifies as "?Glueful\Auth\UserIdentity", so inspect the
        // ReflectionNamedType rather than comparing the stringified form.
        $type = (new \ReflectionMethod(RequestUserContext::class, 'getUser'))->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(UserIdentity::class, $type->getName());
        self::assertTrue($type->allowsNull());
    }
}
