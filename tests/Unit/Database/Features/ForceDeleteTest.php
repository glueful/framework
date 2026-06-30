<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Features;

use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * QueryBuilder::forceDelete() physically removes rows even on a soft-deletable table (one with a
 * deleted_at column), where delete() would otherwise soft-delete.
 */
final class ForceDeleteTest extends TestCase
{
    private function connectionWithSoftDeletableDocs(): Connection
    {
        $conn = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
        $conn->getSchemaBuilder()->createTable('docs', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('name', 50);
            $table->timestamp('deleted_at')->nullable();
        });

        return $conn;
    }

    public function test_force_delete_physically_removes_rows_on_a_soft_deletable_table(): void
    {
        $conn = $this->connectionWithSoftDeletableDocs();
        $conn->table('docs')->insert(['name' => 'a']);

        $conn->table('docs')->where('id', '>', 0)->forceDelete();

        // Gone for good — not even present withTrashed (contrast: delete() would keep the row).
        self::assertSame(0, $conn->table('docs')->withTrashed()->count());
    }

    public function test_delete_soft_deletes_but_force_delete_purges(): void
    {
        $conn = $this->connectionWithSoftDeletableDocs();
        $conn->table('docs')->insert(['name' => 'a']);

        // delete() soft-deletes: hidden from default reads, still physically present.
        $conn->table('docs')->where('id', '>', 0)->delete();
        self::assertSame(0, $conn->table('docs')->count());
        self::assertSame(1, $conn->table('docs')->withTrashed()->count());

        // forceDelete() then purges the soft-deleted row.
        $conn->table('docs')->where('id', '>', 0)->forceDelete();
        self::assertSame(0, $conn->table('docs')->withTrashed()->count());
    }
}
