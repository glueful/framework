<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Migrations;

use Glueful\Database\Migrations\MigrationPriority;
use PHPUnit\Framework\TestCase;

final class MigrationPriorityTest extends TestCase
{
    public function test_tiers_are_strictly_ordered_low_to_high(): void
    {
        self::assertLessThan(MigrationPriority::IDENTITY, MigrationPriority::FOUNDATION);
        self::assertLessThan(MigrationPriority::DEFAULT, MigrationPriority::IDENTITY);
        self::assertLessThan(MigrationPriority::DEPENDENT, MigrationPriority::DEFAULT);
    }

    public function test_default_is_zero(): void
    {
        self::assertSame(0, MigrationPriority::DEFAULT);
    }
}
