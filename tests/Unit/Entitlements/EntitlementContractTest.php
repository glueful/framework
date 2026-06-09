<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Entitlements;

use Glueful\Entitlements\Contracts\EntitlementCheckerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

final class EntitlementContractTest extends TestCase
{
    public function test_interface_shape(): void
    {
        $rc = new ReflectionClass(EntitlementCheckerInterface::class);
        self::assertTrue($rc->isInterface());

        $allows = $rc->getMethod('allows');
        self::assertSame(
            ['tenantUuid', 'entitlement', 'context'],
            array_map(fn($p) => $p->getName(), $allows->getParameters())
        );
        self::assertSame('bool', (string) $allows->getReturnType());
        self::assertTrue($allows->getParameters()[2]->isDefaultValueAvailable());

        $limit = $rc->getMethod('limit');
        self::assertInstanceOf(ReflectionNamedType::class, $limit->getReturnType());
        self::assertTrue($limit->getReturnType()->allowsNull());
        self::assertSame('int', $limit->getReturnType()->getName());
    }
}
