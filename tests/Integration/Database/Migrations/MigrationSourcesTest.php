<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database\Migrations;

use Glueful\Database\Migrations\{MigrationManager, MigrationPriority};
use Glueful\Tests\Integration\Database\Migrations\Support\MigrationTestCase;

final class MigrationSourcesTest extends MigrationTestCase
{
    public function test_registered_sources_carry_priority_and_source_name(): void
    {
        $mm = new MigrationManager($this->tempMigrationsDir(), null, $this->context());
        $pkgDir = $this->tempMigrationsDir() . '/../pkg';
        mkdir($pkgDir, 0755, true);

        $mm->addMigrationPath($pkgDir, MigrationPriority::IDENTITY, 'glueful/users');

        $ref = new \ReflectionMethod(MigrationManager::class, 'allSources');
        $ref->setAccessible(true);
        /** @var array<int,array{path:string,priority:int,source:string}> $sources */
        $sources = $ref->invoke($mm);

        // The main app path is always present as source 'app' at DEFAULT priority.
        $app = array_values(array_filter($sources, fn($s) => $s['source'] === 'app'));
        self::assertCount(1, $app);
        self::assertSame(MigrationPriority::DEFAULT, $app[0]['priority']);

        $users = array_values(array_filter($sources, fn($s) => $s['source'] === 'glueful/users'));
        self::assertCount(1, $users);
        self::assertSame(MigrationPriority::IDENTITY, $users[0]['priority']);
    }
}
