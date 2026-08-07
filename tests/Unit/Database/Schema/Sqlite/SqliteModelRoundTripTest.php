<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Schema\Sqlite;

use Glueful\Database\Schema\Generators\SQLiteSqlGenerator;
use Glueful\Database\Schema\Sqlite\SqliteSchemaIntrospector;
use Glueful\Database\Schema\Sqlite\SqliteSnapshotMapper;
use Glueful\Database\Schema\Sqlite\SqliteTableSnapshot;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The model gate from the spec: introspect(original) -> regenerate DDL in a
 * scratch database -> introspect again must equal the original canonical
 * snapshot, for every table shape the framework's builder can produce.
 */
final class SqliteModelRoundTripTest extends TestCase
{
    /** @return iterable<string, array{ddl: list<string>}> */
    public static function tableShapes(): iterable
    {
        yield 'plain columns with defaults' => ['ddl' => [
            'CREATE TABLE "t" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" TEXT NOT NULL, '
            . '"age" INTEGER DEFAULT 0, "bio" TEXT DEFAULT NULL)',
        ]];
        yield 'enum check emulation' => ['ddl' => [
            'CREATE TABLE "t" ("id" INTEGER PRIMARY KEY, '
            . '"status" TEXT NOT NULL DEFAULT \'draft\' CHECK ("status" IN (\'draft\', \'sent\')))',
        ]];
        yield 'explicit column check' => ['ddl' => [
            'CREATE TABLE "t" ("id" INTEGER PRIMARY KEY, "qty" INTEGER CHECK ("qty" > 0))',
        ]];
        yield 'inline and table-level unique' => ['ddl' => [
            'CREATE TABLE "t" ("id" INTEGER PRIMARY KEY, "email" TEXT UNIQUE, '
            . '"a" TEXT, "b" TEXT, UNIQUE ("a", "b"))',
        ]];
        yield 'foreign keys with actions' => ['ddl' => [
            'CREATE TABLE "teams" ("id" INTEGER PRIMARY KEY)',
            'CREATE TABLE "t" ("id" INTEGER PRIMARY KEY, "team_id" INTEGER NOT NULL, '
            . 'FOREIGN KEY ("team_id") REFERENCES "teams" ("id") ON DELETE CASCADE ON UPDATE RESTRICT)',
        ]];
        yield 'composite primary key without rowid' => ['ddl' => [
            'CREATE TABLE "t" ("a" TEXT NOT NULL, "b" TEXT NOT NULL, PRIMARY KEY ("a", "b")) WITHOUT ROWID',
        ]];
        yield 'table-level check' => ['ddl' => [
            'CREATE TABLE "t" ("lo" INTEGER, "hi" INTEGER, CHECK ("lo" < "hi"))',
        ]];
        yield 'real blob and raw expression defaults' => ['ddl' => [
            'CREATE TABLE "t" ("ratio" REAL DEFAULT 1.5, "payload" BLOB, '
            . '"created_at" TEXT DEFAULT CURRENT_TIMESTAMP)',
        ]];
        yield 'strict table' => ['ddl' => [
            'CREATE TABLE "t" ("id" INTEGER PRIMARY KEY, "body" TEXT) STRICT',
        ]];
        yield 'strict without-rowid table' => ['ddl' => [
            'CREATE TABLE "t" ("a" TEXT NOT NULL, "b" INTEGER NOT NULL, '
            . 'PRIMARY KEY ("a", "b")) WITHOUT ROWID, STRICT',
        ]];
    }

    /** @param list<string> $ddl */
    #[Test]
    #[DataProvider('tableShapes')]
    public function introspectRegenerateIntrospectIsLossless(array $ddl): void
    {
        $original = new PDO('sqlite::memory:');
        $original->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ($ddl as $statement) {
            $original->exec($statement);
        }

        $snapshot = (new SqliteSchemaIntrospector($original))->snapshot('t');
        $definition = (new SqliteSnapshotMapper())->toTableDefinition($snapshot);
        $regeneratedSql = (new SQLiteSqlGenerator())->createTable($definition);

        $scratch = new PDO('sqlite::memory:');
        $scratch->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Referenced tables must exist for FK DDL to be accepted at CREATE time.
        foreach (array_slice($ddl, 0, -1) as $statement) {
            $scratch->exec($statement);
        }
        $scratch->exec($regeneratedSql);

        $regenerated = (new SqliteSchemaIntrospector($scratch))->snapshot('t');

        $this->assertSame(
            $this->canonical($snapshot),
            $this->canonical($regenerated),
            "Round-trip drift.\nOriginal SQL: {$snapshot->createSql}\nRegenerated SQL: {$regeneratedSql}"
        );
    }

    /**
     * Canonical comparable form: everything semantic, nothing cosmetic.
     * Auto-index names are positional (sqlite_autoindex_t_N) and already
     * comparable; declared types compare case-insensitively; the raw
     * createSql is deliberately excluded (it is evidence, not semantics).
     *
     * @return array<string, mixed>
     */
    private function canonical(SqliteTableSnapshot $s): array
    {
        $indexes = array_map(static fn (array $ix): array => [
            'unique' => $ix['unique'],
            'origin' => $ix['origin'],
            'partial' => $ix['partial'],
            'columns' => array_map(
                static fn (?string $c): ?string => $c === null ? null : strtolower($c),
                $ix['columns']
            ),
        ], $s->indexes);
        usort($indexes, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));

        $fks = array_map(static fn (array $fk): array => [
            'table' => strtolower($fk['table']),
            'from' => array_map('strtolower', $fk['from']),
            'to' => array_map('strtolower', $fk['to']),
            'onUpdate' => strtoupper($fk['onUpdate']),
            'onDelete' => strtoupper($fk['onDelete']),
        ], $s->foreignKeys);

        $checks = array_map(static fn (array $c): array => [
            'identifiers' => $c['identifiers'],
            'scope' => $c['scope'],
            'column' => $c['column'],
            'normalized' => strtolower(preg_replace('/\s+/', ' ', $c['expression']) ?? $c['expression']),
        ], $s->checks);

        return [
            'columns' => array_map(static fn (array $c): array => [
                'name' => strtolower($c['name']),
                'type' => strtolower($c['type']),
                'notNull' => $c['notNull'],
                'default' => $c['default'] === null ? null : strtolower($c['default']),
                'pkOrdinal' => $c['pkOrdinal'],
            ], $s->columns),
            'primaryKey' => array_map('strtolower', $s->primaryKey),
            'autoIncrement' => $s->autoIncrement,
            'checks' => $checks,
            'indexes' => $indexes,
            'foreignKeys' => $fks,
            'withoutRowid' => $s->withoutRowid,
            'strict' => $s->strict,
        ];
    }
}
