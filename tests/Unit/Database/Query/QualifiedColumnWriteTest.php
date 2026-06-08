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
}
