<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database;

use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;

final class InsertHookTest extends TestCase
{
    protected function tearDown(): void
    {
        Connection::clearInsertHooks();
        parent::tearDown();
    }

    private function sqliteConnection(): Connection
    {
        $conn = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
        $conn->getPDO()->exec(
            'CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, tenant_uuid TEXT)'
        );
        return $conn;
    }

    public function testInsertHookStampsMissingColumn(): void
    {
        Connection::addInsertHook(static function (string $table, array $data): array {
            if ($table === 'widgets' && !isset($data['tenant_uuid'])) {
                $data['tenant_uuid'] = 'T-STAMP';
            }
            return $data;
        });

        $conn = $this->sqliteConnection();
        $conn->table('widgets')->insert(['name' => 'a']);

        self::assertSame('T-STAMP', $conn->table('widgets')->where('name', 'a')->first()['tenant_uuid']);
    }

    public function testInsertBatchStampsEveryRow(): void
    {
        Connection::addInsertHook(static function (string $table, array $data): array {
            $data['tenant_uuid'] = 'T-BATCH';
            return $data;
        });

        $conn = $this->sqliteConnection();
        $conn->table('widgets')->insertBatch([['name' => 'a'], ['name' => 'b']]);

        $rows = $conn->table('widgets')->orderBy('name', 'asc')->get();
        self::assertSame(['T-BATCH', 'T-BATCH'], array_column($rows, 'tenant_uuid'));
    }

    public function testNoHookRegisteredIsNoOp(): void
    {
        $conn = $this->sqliteConnection();
        $conn->table('widgets')->insert(['name' => 'a', 'tenant_uuid' => 'kept']);

        self::assertSame('kept', $conn->table('widgets')->where('name', 'a')->first()['tenant_uuid']);
    }

    public function testHookReturningNonAssociativeArrayThrows(): void
    {
        Connection::addInsertHook(static fn (string $table, array $data): array => array_values($data)); // list-shaped

        $conn = $this->sqliteConnection();
        $this->expectException(\UnexpectedValueException::class);
        $conn->table('widgets')->insert(['name' => 'a']);
    }

    public function testInsertBatchWithInconsistentColumnsAfterHooksThrows(): void
    {
        // A hook that adds a column to only some rows makes the batch column set non-uniform.
        Connection::addInsertHook(static function (string $table, array $data): array {
            if (($data['name'] ?? null) === 'b') {
                $data['extra'] = 1;
            }
            return $data;
        });

        $conn = $this->sqliteConnection();
        $this->expectException(\UnexpectedValueException::class);
        $conn->table('widgets')->insertBatch([['name' => 'a'], ['name' => 'b']]);
    }

    public function testInsertBatchNormalizesRowKeyOrderToFirstRow(): void
    {
        // Same column SET, different key ORDER per row: uniformity passes (set compare) and
        // normalization must reorder row 2 to the first row's column order, so values land in
        // the right columns rather than being bound positionally by the wrong order.
        $conn = $this->sqliteConnection();
        $conn->table('widgets')->insertBatch([
            ['name' => 'a', 'tenant_uuid' => 'T1'],
            ['tenant_uuid' => 'T2', 'name' => 'b'], // deliberately reversed key order
        ]);

        $rows = $conn->table('widgets')->orderBy('name', 'asc')->get();
        self::assertSame(['a', 'b'], array_column($rows, 'name'));
        self::assertSame(['T1', 'T2'], array_column($rows, 'tenant_uuid'), 'values stayed aligned to their columns');
    }
}
