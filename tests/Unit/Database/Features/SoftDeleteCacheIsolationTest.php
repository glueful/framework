<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Features;

use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Regression: the "does this table have a deleted_at column?" cache must be scoped
 * per connection, not shared process-wide by table name alone.
 *
 * Two connections with a same-named table but different schemas previously poisoned
 * each other's cache: whichever ran first decided soft-vs-hard delete for the other,
 * causing either irreversible hard-deletes of would-be-soft rows, soft-deleted rows
 * leaking into reads, or a "no such column: deleted_at" error.
 */
final class SoftDeleteCacheIsolationTest extends TestCase
{
    private function connection(): Connection
    {
        return new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
    }

    public function test_deleted_at_column_cache_is_not_shared_across_connections(): void
    {
        // Connection A: 'docs' HAS deleted_at -> soft delete.
        $connA = $this->connection();
        $connA->getSchemaBuilder()->createTable('docs', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('name', 50);
            $table->timestamp('deleted_at')->nullable();
        });
        $connA->table('docs')->insert(['name' => 'a']);

        // Connection B: same table NAME, NO deleted_at column -> must hard delete.
        $connB = $this->connection();
        $connB->getSchemaBuilder()->createTable('docs', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('name', 50);
        });
        $connB->table('docs')->insert(['name' => 'b']);

        // Prime A's cache first (docs => has deleted_at).
        $connA->table('docs')->where('id', '>', 0)->delete();

        // B must consult ITS OWN schema (no deleted_at) and hard-delete. With a
        // process-global table-keyed cache, B would inherit A's "true" and attempt a
        // soft delete against a column that does not exist on B.
        $connB->table('docs')->where('id', '>', 0)->delete();
        self::assertSame(0, $connB->table('docs')->count(), 'connection B should hard-delete its row');

        // A soft-deleted: hidden from default reads, still physically present.
        self::assertSame(0, $connA->table('docs')->count(), 'A row is soft-deleted (hidden from reads)');
        self::assertSame(
            1,
            $connA->table('docs')->withTrashed()->count(),
            'A row is still physically present (soft delete, not hard delete)'
        );
    }
}
