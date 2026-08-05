<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Query;

use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * orWhereRaw() on the query builder.
 *
 * Regression coverage: QueryBuilder had whereRaw() but no orWhereRaw(), yet the
 * ORM's has()/whereHas() machinery dispatched to `orWhereRaw` for the 'or'
 * boolean — so orHas()/orDoesntHave()/orWhereHas()/orWhereDoesntHave() fataled
 * with "Call to undefined method" (surfaced by the PHPStan 2 upgrade).
 */
final class OrWhereRawTest extends TestCase
{
    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }

        parent::tearDown();
    }

    public function test_or_where_raw_widens_selection_with_or_semantics(): void
    {
        $conn = $this->seededConnection();

        $codes = array_map(
            static fn (array $r): string => (string) $r['code'],
            $conn->table('items')
                ->where('code', '=', 'a')
                ->orWhereRaw('grade = ?', [7])
                ->orderBy('code')
                ->get()
        );

        // a matches the basic condition; b and d match the raw OR condition.
        self::assertSame(['a', 'b', 'd'], array_values($codes));
    }

    public function test_or_where_raw_as_first_condition_selects_matching_rows(): void
    {
        $conn = $this->seededConnection();

        $codes = array_map(
            static fn (array $r): string => (string) $r['code'],
            $conn->table('items')
                ->orWhereRaw('grade = ?', [7])
                ->orderBy('code')
                ->get()
        );

        self::assertSame(['b', 'd'], array_values($codes));
    }

    private function seededConnection(): Connection
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'orwhereraw-');
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
        });

        foreach (['a' => 0, 'b' => 7, 'c' => 0, 'd' => 7] as $code => $grade) {
            $conn->table('items')->insert(['code' => $code, 'grade' => $grade]);
        }

        return $conn;
    }
}
