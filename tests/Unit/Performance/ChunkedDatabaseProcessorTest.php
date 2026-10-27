<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Performance;

use Glueful\Database\Connection;
use Glueful\Performance\ChunkedDatabaseProcessor;
use PHPUnit\Framework\TestCase;

/**
 * ChunkedDatabaseProcessor must accept a Glueful Connection (and keep PDO support).
 */
final class ChunkedDatabaseProcessorTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
        $pdo = $this->connection->getPDO();
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, status TEXT NOT NULL)');
        foreach ([1 => 'a', 2 => 'b', 3 => 'a', 4 => 'a', 5 => 'b', 6 => 'a'] as $id => $status) {
            $pdo->exec("INSERT INTO items (id, status) VALUES ({$id}, '{$status}')");
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private static function ids(array $rows): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], array_values($rows));
    }

    public function testProcessTableInChunksWithConnection(): void
    {
        $processor = new ChunkedDatabaseProcessor($this->connection, 2);

        $chunks = $processor->processTableInChunks('items', static fn (array $rows): array => self::ids($rows));

        self::assertSame([[1, 2], [3, 4], [5, 6]], $chunks);
    }

    public function testProcessTableInChunksWithConnectionAppliesConditions(): void
    {
        $processor = new ChunkedDatabaseProcessor($this->connection);

        $chunks = $processor->processTableInChunks(
            'items',
            static fn (array $rows): array => self::ids($rows),
            'id',
            ['status' => 'a'],
            3
        );

        self::assertSame([[1, 3, 4], [6]], $chunks);
    }

    public function testProcessSelectQueryWithConnection(): void
    {
        $processor = new ChunkedDatabaseProcessor($this->connection, 4);

        $chunks = $processor->processSelectQuery(
            'SELECT id FROM items WHERE status = ? ORDER BY id',
            static fn (array $rows): array => self::ids($rows),
            ['a']
        );

        self::assertSame([[1, 3, 4, 6]], $chunks);
    }

    public function testPdoStillSupported(): void
    {
        $processor = new ChunkedDatabaseProcessor($this->connection->getPDO(), 4);

        $chunks = $processor->processTableInChunks('items', static fn (array $rows): array => self::ids($rows));

        self::assertSame([[1, 2, 3, 4], [5, 6]], $chunks);
    }

    public function testUnsupportedConnectionTypeIsRejectedAtConstruction(): void
    {
        $this->expectException(\TypeError::class);
        /** @phpstan-ignore-next-line deliberately invalid argument */
        new ChunkedDatabaseProcessor(new \stdClass());
    }
}
