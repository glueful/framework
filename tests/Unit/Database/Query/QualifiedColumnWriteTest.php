<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Query;

use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Regression: a table-qualified WHERE column (e.g. `items.tenant_uuid`) must work on
 * UPDATE/DELETE, not just SELECT.
 *
 * getConditionsArray() parses the built WHERE SQL back into column => value pairs that
 * validateUpdate()/validateDelete() check and the update/delete builders rebuild from.
 * It used to trim only the OUTER wrap chars off a qualified identifier, leaving a stray
 * quote on the bare column (e.g. `"tenant_uuid`) after splitting on the table separator —
 * which the column validator's injection check then rejected, throwing
 * InvalidArgumentException before the write could run.
 */
final class QualifiedColumnWriteTest extends TestCase
{
    private function sqliteConnection(): Connection
    {
        $conn = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);

        $conn->getSchemaBuilder()->createTable('items', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('tenant_uuid', 12);
            $table->string('name', 255);
        });

        $conn->table('items')->insert(['tenant_uuid' => 'tenantAAAAAA', 'name' => 'a-one']);
        $conn->table('items')->insert(['tenant_uuid' => 'tenantAAAAAA', 'name' => 'a-two']);
        $conn->table('items')->insert(['tenant_uuid' => 'tenantBBBBBB', 'name' => 'b-one']);

        return $conn;
    }

    public function test_qualified_column_update_runs_and_scopes_correctly(): void
    {
        $conn = $this->sqliteConnection();

        // Table-qualified predicate on UPDATE — previously threw InvalidArgumentException.
        $affected = $conn->table('items')
            ->where('items.tenant_uuid', 'tenantAAAAAA')
            ->update(['name' => 'updated']);

        self::assertSame(2, $affected, 'qualified UPDATE should affect only tenant A\'s two rows');

        // Tenant A's rows updated; tenant B untouched (predicate applied correctly).
        $bName = $conn->table('items')->where('tenant_uuid', 'tenantBBBBBB')->first()['name'];
        self::assertSame('b-one', $bName);
        $aNames = $conn->table('items')->where('tenant_uuid', 'tenantAAAAAA')->get();
        foreach ($aNames as $row) {
            self::assertSame('updated', $row['name']);
        }
    }

    public function test_qualified_column_delete_runs_and_scopes_correctly(): void
    {
        $conn = $this->sqliteConnection();

        $deleted = $conn->table('items')
            ->where('items.tenant_uuid', 'tenantAAAAAA')
            ->delete();

        self::assertSame(2, $deleted, 'qualified DELETE should remove only tenant A\'s two rows');

        // Tenant B survives.
        self::assertSame(1, $conn->table('items')->where('tenant_uuid', 'tenantBBBBBB')->count());
        self::assertSame(0, $conn->table('items')->where('tenant_uuid', 'tenantAAAAAA')->count());
    }

    /**
     * Two predicates on the SAME column (a range `id > 1 AND id < 3`) must BOTH apply.
     * The old reparse keyed conditions by column name and silently collapsed them to the
     * last predicate (`id < 3`), deleting MORE rows than intended. They are now folded
     * into a __multi list so the write builders emit both predicates AND-joined.
     */
    public function test_range_predicate_on_delete_affects_only_matching_rows(): void
    {
        $conn = $this->sqliteConnection();
        // ids are 1, 2, 3. The range "id > 1 AND id < 3" matches only id = 2.
        $deleted = $conn->table('items')
            ->where('id', '>', 1)
            ->where('id', '<', 3)
            ->delete();

        self::assertSame(1, $deleted, 'only the single in-range row (id = 2) should be deleted');
        // ids 1 and 3 survive; a collapse to "id < 3" would have wrongly removed id 1.
        self::assertSame(2, $conn->table('items')->count());
        $remaining = array_column($conn->table('items')->orderBy('id')->get(), 'id');
        self::assertSame([1, 3], array_map('intval', $remaining));
    }

    /**
     * The same range support must hold for UPDATE.
     */
    public function test_range_predicate_on_update_affects_only_matching_rows(): void
    {
        $conn = $this->sqliteConnection();

        $affected = $conn->table('items')
            ->where('id', '>', 1)
            ->where('id', '<', 3)
            ->update(['name' => 'ranged']);

        self::assertSame(1, $affected, 'only id = 2 should be updated');
        self::assertSame('ranged', $conn->table('items')->where('id', 2)->first()['name']);
        self::assertNotSame('ranged', $conn->table('items')->where('id', 1)->first()['name']);
        self::assertNotSame('ranged', $conn->table('items')->where('id', 3)->first()['name']);
    }

    /**
     * Regression guard: predicates on DISTINCT columns must keep working after the
     * duplicate-column guard is added.
     */
    public function test_distinct_column_predicates_still_work_on_delete(): void
    {
        $conn = $this->sqliteConnection();

        $deleted = $conn->table('items')
            ->where('tenant_uuid', 'tenantAAAAAA')
            ->where('name', 'a-one')
            ->delete();

        self::assertSame(1, $deleted);
        self::assertSame(2, $conn->table('items')->count());
    }
}
