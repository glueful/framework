<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database;

use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;

final class SelectRawBindingsTest extends TestCase
{
    private string $dbPath;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = sys_get_temp_dir() . '/glueful-selectraw-' . uniqid('', true) . '.sqlite';
        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dbPath],
            'pooling' => ['enabled' => false],
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        parent::tearDown();
    }

    public function testSelectRawWithoutBindingsAddsNoBindings(): void
    {
        $qb = $this->connection->table('users')->selectRaw('COUNT(*) AS c');

        $this->assertSame([], $qb->getBindings());
        $this->assertStringContainsString('COUNT(*) AS c', $qb->toSql());
    }

    public function testSelectRawWithBindingsRendersPlaceholderAndBinds(): void
    {
        $qb = $this->connection->table('users')
            ->selectRaw('CASE WHEN age > ? THEN 1 ELSE 0 END AS is_adult', [18]);

        $this->assertStringContainsString('?', $qb->toSql());
        $this->assertSame([18], $qb->getBindings());
    }

    public function testGetBindingsReturnsSelectRawBindingsBeforeWhereAndHaving(): void
    {
        // Uses the explicit 3-arg where() form: the 2-arg shorthand only
        // normalizes non-string operands, so where('status', 'paid') would
        // treat 'paid' as the operator.
        $qb = $this->connection->table('orders')
            ->selectRaw('(price * ?) AS total', [1.2])
            ->where('status', '=', 'paid')
            ->havingRaw('total > ?', [100]);

        $this->assertSame([1.2, 'paid', 100], $qb->getBindings());
    }

    public function testSelectAfterSelectRawClearsStaleBindings(): void
    {
        $qb = $this->connection->table('users')
            ->selectRaw('(age * ?) AS x', [2])
            ->select(['name']);

        $this->assertSame([], $qb->getBindings());
        $this->assertStringNotContainsString('?', $qb->toSql());
    }

    public function testCloneIsolatesSelectRawBindingsAndColumns(): void
    {
        $qb = $this->connection->table('users')->selectRaw('(a * ?) AS x', [2]);

        $clone = $qb->clone();
        $clone->selectRaw('(b * ?) AS y', [3]);

        // Original keeps exactly its one expression and one binding.
        $this->assertSame([2], $qb->getBindings());
        $this->assertStringContainsString('(a * ?) AS x', $qb->toSql());
        $this->assertStringNotContainsString('(b * ?) AS y', $qb->toSql());

        // Clone has both expressions and both bindings, and they line up
        // (placeholder count == binding count) — guards against the clone
        // rendering columns from the original state.
        $this->assertSame([2, 3], $clone->getBindings());
        $cloneSql = $clone->toSql();
        $this->assertStringContainsString('(a * ?) AS x', $cloneSql);
        $this->assertStringContainsString('(b * ?) AS y', $cloneSql);
        $this->assertSame(2, substr_count($cloneSql, '?'));
    }

    public function testBoundSelectRawExecutesAndReturnsComputedColumn(): void
    {
        $pdo = $this->connection->getPDO();
        $pdo->exec('CREATE TABLE people (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, age INTEGER)');
        $pdo->exec("INSERT INTO people (name, age) VALUES ('Alice', 30), ('Bob', 15)");

        $rows = $this->connection->table('people')
            ->select(['name'])
            ->selectRaw('CASE WHEN age >= ? THEN ? ELSE ? END AS band', [18, 'adult', 'minor'])
            ->orderBy('name')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('adult', $rows[0]['band']);
        $this->assertSame('Bob', $rows[1]['name']);
        $this->assertSame('minor', $rows[1]['band']);
    }
}
