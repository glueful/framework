<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Schema;

use Glueful\Database\Schema\Generators\PostgreSQLSqlGenerator;
use PHPUnit\Framework\TestCase;

/**
 * `hasTable()` must report EXISTENCE, not the current role's privileges.
 *
 * `information_schema.tables` only lists tables the current role can access, so a table
 * owned by another role (created, say, during a mis-credentialed first boot) made
 * `hasTable()` answer false — and the caller's guarded CREATE then collided with a
 * "Duplicate table" error that pointed nowhere near the real cause. The generator must
 * query `pg_catalog.pg_tables`, which is privilege-blindness-free: existence is existence,
 * and any access problem surfaces later on the actual operation with PostgreSQL's own
 * honest error. (Surfaced by a clean-machine first-run install audit.)
 */
final class PostgresTableExistenceQueryTest extends TestCase
{
    public function test_table_existence_reads_the_catalog_not_the_privilege_filtered_schema(): void
    {
        $sql = (new PostgreSQLSqlGenerator())->tableExistsQuery('migrations');

        self::assertStringContainsString('pg_catalog.pg_tables', $sql);
        self::assertStringContainsString("tablename = 'migrations'", $sql);
        self::assertStringContainsString('current_schema()', $sql, 'schema scoping must be preserved');
        self::assertStringNotContainsString(
            'information_schema',
            $sql,
            'the information schema is privilege-filtered and must not answer existence'
        );
    }
}
