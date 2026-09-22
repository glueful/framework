<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Query;

use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Text search wrote `column LIKE '%term%'` straight from user input: case-sensitive on
 * PostgreSQL (MySQL and SQLite fold case, so the same filter behaved differently per database),
 * and a `%` or `_` in the term was a wildcard. whereContains() and its siblings fold case on every
 * driver and match the term literally.
 */
final class WhereTextTest extends TestCase
{
    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        parent::tearDown();
    }

    public function testContainsFoldsCaseInTheSqlOnEveryDriver(): void
    {
        $query = $this->seededConnection()->table('items')->whereContains('name', 'Report');

        self::assertStringContainsString('LOWER("name") LIKE ? ESCAPE \'!\'', $query->toSql());
        self::assertSame(['%report%'], $query->getBindings());
    }

    public function testContainsMatchesAnyCase(): void
    {
        self::assertSame(['Annual Report', 'report-draft'], $this->names(
            $this->seededConnection()->table('items')->whereContains('name', 'REPORT'),
        ));
    }

    public function testWildcardsInTheTermAreLiteral(): void
    {
        $conn = $this->seededConnection();
        self::assertSame(['100% done'], $this->names($conn->table('items')->whereContains('name', '100%')));
        self::assertSame(['a_b'], $this->names($conn->table('items')->whereContains('name', '_')));
        self::assertSame(['wow!'], $this->names($conn->table('items')->whereContains('name', 'w!')));
    }

    public function testStartsAndEndsAnchorTheTerm(): void
    {
        $conn = $this->seededConnection();
        self::assertSame(['report-draft'], $this->names($conn->table('items')->whereStartsWith('name', 'Report')));
        self::assertSame(['Annual Report'], $this->names($conn->table('items')->whereEndsWith('name', 'report')));
    }

    public function testOrWhereContainsInsideANestedGroup(): void
    {
        $query = $this->seededConnection()->table('items')->where(function ($q): void {
            $q->whereContains('name', 'annual');
            $q->orWhereContains('name', 'wow');
        });

        self::assertSame(['Annual Report', 'wow!'], $this->names($query));
    }

    public function testTheContainsFilterOperatorFoldsCaseAndMatchesLiterally(): void
    {
        $query = $this->seededConnection()->table('items');
        (new \Glueful\Api\Filtering\Operators\ContainsOperator())->apply($query, 'name', '100%');

        self::assertSame(['100% done'], $this->names($query));
    }

    /** @return list<string> */
    private function names(\Glueful\Database\QueryBuilder $query): array
    {
        return array_values(array_map(
            static fn (array $r): string => (string) $r['name'],
            $query->orderBy('name')->get(),
        ));
    }

    private function seededConnection(): Connection
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'wheretext-');
        self::assertIsString($this->dbPath);

        $conn = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dbPath],
            'pooling' => ['enabled' => false],
        ]);
        $pdo = $conn->getPDO();
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
        $insert = $pdo->prepare('INSERT INTO items (name) VALUES (?)');
        foreach (['Annual Report', 'report-draft', '100% done', '100 done', 'a_b', 'axb', 'wow!', 'wo'] as $name) {
            $insert->execute([$name]);
        }

        return $conn;
    }
}
