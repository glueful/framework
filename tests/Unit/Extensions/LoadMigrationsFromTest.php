<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Database\Migrations\{MigrationManager, MigrationPriority};
use Glueful\Extensions\ServiceProvider;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

final class LoadMigrationsFromTest extends TestCase
{
    public function test_forwards_priority_and_source_to_manager(): void
    {
        $dir = sys_get_temp_dir() . '/lmf-' . uniqid('', true);
        mkdir($dir, 0755, true);

        $mm = $this->createMock(MigrationManager::class);
        $mm->expects(self::once())
            ->method('addMigrationPath')
            ->with($dir, MigrationPriority::IDENTITY, 'glueful/users');

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($mm);

        $provider = new class ($container) extends ServiceProvider {
            public function callLoad(string $d): void
            {
                $this->loadMigrationsFrom($d, MigrationPriority::IDENTITY, 'glueful/users');
            }
        };
        $provider->callLoad($dir);

        rmdir($dir);
    }
}
