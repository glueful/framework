<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Query;

use Glueful\Database\Connection;
use Glueful\Database\Query\Interfaces\QueryBuilderInterface;
use Glueful\Database\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * QueryBuilder::optimize() never had a consumer (it only flipped a flag no code
 * read). It is deprecated and an explicit no-op returning the same builder.
 */
final class OptimizeDeprecationTest extends TestCase
{
    public function testOptimizeIsDeprecatedOnBuilderAndInterface(): void
    {
        foreach ([QueryBuilder::class, QueryBuilderInterface::class] as $class) {
            $doc = (string) (new \ReflectionMethod($class, 'optimize'))->getDocComment();
            self::assertStringContainsString('@deprecated', $doc, "{$class}::optimize() must be @deprecated");
        }
    }

    public function testOptimizeIsANoOpReturningSameBuilder(): void
    {
        $connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
        $connection->getPDO()->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $connection->getPDO()->exec('INSERT INTO t (id) VALUES (1)');

        $qb = $connection->table('t');
        self::assertSame($qb, $qb->optimize());
        self::assertSame([['id' => 1]], $qb->get());
        self::assertFalse(
            (new \ReflectionClass(QueryBuilder::class))->hasProperty('optimizeEnabled'),
            'The dead optimizeEnabled flag should be removed'
        );
    }
}
