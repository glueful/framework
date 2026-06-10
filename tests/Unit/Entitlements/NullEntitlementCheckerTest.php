<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Entitlements;

use Glueful\Entitlements\Contracts\EntitlementCheckerInterface;
use Glueful\Entitlements\NullEntitlementChecker;
use PHPUnit\Framework\TestCase;

final class NullEntitlementCheckerTest extends TestCase
{
    public function test_allows_everything_and_limits_are_unlimited(): void
    {
        $checker = new NullEntitlementChecker();

        self::assertInstanceOf(EntitlementCheckerInterface::class, $checker);
        self::assertTrue($checker->allows('tenant-1', 'reports.export'));
        self::assertTrue($checker->allows('tenant-1', 'anything.at.all', ['resource' => 9]));
        self::assertNull($checker->limit('tenant-1', 'projects.limit'));
        self::assertNull($checker->limit('tenant-1', 'api.monthly', ['x' => 1]));
    }
}
