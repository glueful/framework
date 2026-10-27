<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Providers\CoreProvider;
use Glueful\Container\Providers\TagCollector;
use Glueful\Permissions\PermissionManager;
use PHPUnit\Framework\TestCase;

final class PermissionManagerBindingTest extends TestCase
{
    public function test_class_id_and_string_alias_resolve_the_same_shared_manager(): void
    {
        // tests/Unit/Permissions -> framework root is dirname(__DIR__, 3)
        $context = ApplicationContext::forTesting(dirname(__DIR__, 3));
        $container = new Container((new CoreProvider(new TagCollector(), $context))->defs());

        self::assertTrue($container->has(PermissionManager::class));
        self::assertTrue($container->has('permission.manager'));

        $byClass = $container->get(PermissionManager::class);
        self::assertInstanceOf(PermissionManager::class, $byClass);
        self::assertSame($byClass, $container->get('permission.manager'));
    }
}
