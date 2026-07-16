<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Query;

use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * whereIn()/whereNotIn() on UPDATE and DELETE.
 *
 * Regression coverage: WhereClause::getConditionsArray() only reparsed
 * single-placeholder raw conditions, so whereIn's multi-placeholder
 * `col IN (?, ?, ?)` was classified "complex" and UPDATE/DELETE threw
 * "Complex WHERE conditions ... not yet supported" — even though the
 * write builders already emit IN/NOT IN for array-valued conditions.
 */
final class WhereInWriteOperationsTest extends TestCase
{
    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }

        parent::tearDown();
    }

    public function test_where_in_delete_removes_only_matching_rows(): void
    {
        $conn = $this->seededConnection();

        $affected = $conn->table('items')
            ->whereIn('code', ['a', 'c'])
            ->delete();

        self::assertSame(2, $affected);
        self::assertSame(['b', 'd'], $this->codes($conn));
    }

    public function test_where_not_in_delete_keeps_listed_rows(): void
    {
        $conn = $this->seededConnection();

        $affected = $conn->table('items')
            ->whereNotIn('code', ['a', 'b'])
            ->delete();

        self::assertSame(2, $affected);
        self::assertSame(['a', 'b'], $this->codes($conn));
    }

    public function test_where_in_update_touches_only_matching_rows(): void
    {
        $conn = $this->seededConnection();

        $affected = $conn->table('items')
            ->whereIn('code', ['b', 'd'])
            ->update(['grade' => 9]);

        self::assertSame(2, $affected);
        $grades = [];
        foreach ($conn->table('items')->orderBy('code')->get() as $row) {
            $grades[$row['code']] = (int) $row['grade'];
        }
        self::assertSame(['a' => 0, 'b' => 9, 'c' => 0, 'd' => 9], $grades);
    }

    public function test_where_in_composes_with_preceding_basic_condition(): void
    {
        // Binding-order accounting: the basic condition consumes one binding
        // BEFORE the IN condition's slice; a mis-offset would shift values.
        $conn = $this->seededConnection();

        $affected = $conn->table('items')
            ->where('grade', '=', 0)
            ->whereIn('code', ['a', 'b'])
            ->update(['grade' => 5]);

        self::assertSame(2, $affected);
        self::assertSame(
            2,
            count(array_filter(
                $conn->table('items')->get(),
                static fn (array $r): bool => (int) $r['grade'] === 5
            ))
        );
    }

    public function test_where_in_after_null_check_keeps_binding_offsets(): void
    {
        // whereNull consumes zero bindings; the IN slice must still start at 0.
        $conn = $this->seededConnection();

        $affected = $conn->table('items')
            ->whereNull('note')
            ->whereIn('code', ['c'])
            ->delete();

        self::assertSame(1, $affected);
        self::assertSame(['a', 'b', 'd'], $this->codes($conn));
    }

    public function test_empty_where_in_still_rejects_write_operations(): void
    {
        // whereIn([]) becomes the raw always-false '1 = 0', which the simple
        // conditions map cannot express — the guarded throw stays (documented).
        $conn = $this->seededConnection();

        $this->expectException(\RuntimeException::class);
        $conn->table('items')->whereIn('code', [])->delete();
    }

    private function seededConnection(): Connection
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'wherein-write-');
        self::assertIsString($this->dbPath);

        $conn = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dbPath],
            'pooling' => ['enabled' => false],
        ]);

        $conn->getSchemaBuilder()->createTable('items', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('code', 8);
            $table->integer('grade')->default(0);
            $table->string('note', 32)->nullable();
        });

        foreach (['a', 'b', 'c', 'd'] as $code) {
            $conn->table('items')->insert(['code' => $code, 'grade' => 0, 'note' => null]);
        }

        return $conn;
    }

    /** @return list<string> remaining codes, ordered */
    private function codes(Connection $conn): array
    {
        $codes = array_map(
            static fn (array $r): string => (string) $r['code'],
            $conn->table('items')->orderBy('code')->get()
        );

        return array_values($codes);
    }
}
