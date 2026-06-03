<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Extensions\ServiceProvider;
use Glueful\Permissions\Catalog\{Permission, Role};
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

final class ServiceProviderDeclarationTest extends TestCase
{
    public function test_base_provider_declares_nothing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $provider = new class ($container) extends ServiceProvider {};
        self::assertSame([], $provider->permissions());
        self::assertSame([], $provider->roles());
    }

    public function test_subclass_can_declare(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $provider = new class ($container) extends ServiceProvider {
            public function permissions(): array
            {
                return [Permission::define('blog.publish')];
            }
            public function roles(): array
            {
                return [Role::define('blog.editor')->grants(['blog.publish'])];
            }
        };
        self::assertSame('blog.publish', $provider->permissions()[0]->slug());
        self::assertSame('blog.editor', $provider->roles()[0]->slug());
    }
}
