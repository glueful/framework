<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Execution;

use Glueful\Database\Execution\ParameterBinder;
use Glueful\Database\Execution\QueryExecutor;
use Glueful\Database\QueryLogger;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * An insert whose key the database fills without a sequence (a UUID column default, say) has no
 * generated id to read: PostgreSQL's lastInsertId() throws "lastval is not yet defined". That must
 * not fail the insert that already ran; there is simply no id to report.
 */
final class InsertGetIdTest extends TestCase
{
    public function testAnAutoIncrementInsertReportsItsId(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $executor = new QueryExecutor($pdo, new ParameterBinder(), new QueryLogger());

        $executor->executeInsertGetId('INSERT INTO t (name) VALUES (?)', ['a']);

        self::assertSame(2, $executor->executeInsertGetId('INSERT INTO t (name) VALUES (?)', ['b']));
    }

    public function testAnInsertWithNoGeneratedIdReportsNullInsteadOfFailing(): void
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            public function lastInsertId(?string $name = null): string|false
            {
                throw new \PDOException('lastval is not yet defined in this session');
            }
        };
        $pdo->exec('CREATE TABLE u (id TEXT PRIMARY KEY, name TEXT)');
        $executor = new QueryExecutor($pdo, new ParameterBinder(), new QueryLogger());

        self::assertNull($executor->executeInsertGetId("INSERT INTO u (id, name) VALUES ('x', ?)", ['a']));
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM u')->fetchColumn(), 'the insert ran');
    }
}
