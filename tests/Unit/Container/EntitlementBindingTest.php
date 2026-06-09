<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Providers\CoreProvider;
use Glueful\Container\Providers\TagCollector;
use Glueful\Entitlements\Contracts\EntitlementCheckerInterface;
use Glueful\Entitlements\NullEntitlementChecker;
use PHPUnit\Framework\TestCase;

final class EntitlementBindingTest extends TestCase
{
    public function test_core_binds_entitlement_checker_to_null_default(): void
    {
        // tests/Unit/Container -> framework root is dirname(__DIR__, 3)
        $context = ApplicationContext::forTesting(dirname(__DIR__, 3));
        $provider = new CoreProvider(new TagCollector(), $context);

        $container = new Container($provider->defs());

        $checker = $container->get(EntitlementCheckerInterface::class);

        self::assertInstanceOf(NullEntitlementChecker::class, $checker);
    }
}
