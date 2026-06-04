<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Migrations;

use Glueful\Database\Migrations\MigrationManager;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Tests\Integration\Database\Migrations\Support\MigrationTestCase;

/**
 * With multiple sources each shipping the same basename at the same priority, pending order must be
 * deterministic — tie-broken by source. (source-tracking already prevents duplicate *application*;
 * this guards deterministic *ordering*.)
 */
final class PendingSortTiebreakerTest extends MigrationTestCase
{
    public function test_same_basename_different_sources_sort_by_source(): void
    {
        // Place the capability fixtures OUTSIDE the scanned main path — findMigrations() recurses,
        // so subdirs under the main migrations dir would be double-counted under the 'app' source.
        $caps = $this->getBasePath() . '/caps';
        $a = $caps . '/a';
        $b = $caps . '/b';
        $this->writeFixture($a, '001_create_tables.php', 'cap_from_a');
        $this->writeFixture($b, '001_create_tables.php', 'cap_from_b');

        $mm = new MigrationManager($this->tempMigrationsDir(), null, $this->context());
        $mm->addMigrationPath($a, MigrationPriority::FOUNDATION, 'zsrc');
        $mm->addMigrationPath($b, MigrationPriority::FOUNDATION, 'asrc');

        $pending = $mm->getPendingMigrations(); // full paths

        // Both: priority FOUNDATION, basename '001_create_tables.php' — tiebreak by source ASC,
        // so 'asrc' (dir b) precedes 'zsrc' (dir a).
        self::assertCount(2, $pending);
        self::assertStringEndsWith('/b/001_create_tables.php', $pending[0]);
        self::assertStringEndsWith('/a/001_create_tables.php', $pending[1]);
    }
}
