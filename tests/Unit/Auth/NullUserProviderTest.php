<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth;

use Glueful\Auth\NullUserProvider;
use PHPUnit\Framework\TestCase;

final class NullUserProviderTest extends TestCase
{
    public function test_everything_resolves_to_null_fail_closed(): void
    {
        $p = new NullUserProvider();
        self::assertNull($p->findByUuid('u1'));
        self::assertNull($p->findByLogin('a@b.test'));
        self::assertNull($p->verifyCredentials('a@b.test', 'secret'));
    }
}
