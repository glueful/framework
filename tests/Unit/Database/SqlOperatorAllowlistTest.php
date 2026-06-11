<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database;

use Glueful\Database\Connection;
use Glueful\Database\Driver\MySQLDriver;
use Glueful\Database\Driver\PostgreSQLDriver;
use Glueful\Database\Driver\SQLiteDriver;
use Glueful\Database\Query\QueryModifiers;
use Glueful\Database\Query\SqlOperators;
use Glueful\Database\Query\WhereClause;
use PHPUnit\Framework\TestCase;

/**
 * Operators and identifiers that get interpolated raw into SQL (JOIN/HAVING/ORM has(),
 * JSON paths, wrapped identifiers) must be allow-listed / escaped so an app forwarding
 * request input into them cannot inject SQL.
 */
final class SqlOperatorAllowlistTest extends TestCase
{
    public function test_assert_operator_accepts_known_and_rejects_unknown(): void
    {
        self::assertSame('=', SqlOperators::assertOperator('='));
        self::assertSame('<=', SqlOperators::assertOperator('<='));
        self::assertSame('LIKE', SqlOperators::assertOperator('like', SqlOperators::COMPARISON_AND_LIKE));

        $this->expectException(\InvalidArgumentException::class);
        SqlOperators::assertOperator('= OR 1=1 --');
    }

    public function test_assert_join_type_rejects_unknown(): void
    {
        self::assertSame('LEFT', SqlOperators::assertJoinType('left'));

        $this->expectException(\InvalidArgumentException::class);
        SqlOperators::assertJoinType('EVIL');
    }

    public function test_join_with_unsafe_operator_is_rejected(): void
    {
        $conn = $this->sqlite();
        $this->expectException(\InvalidArgumentException::class);
        $conn->table('items')->join('others', 'items.id', ') OR 1=1 --', 'others.id');
    }

    public function test_having_with_unsafe_operator_is_rejected(): void
    {
        // QueryModifiers::having() accepts an operator argument that is interpolated raw;
        // (QueryBuilder::having() hardcodes '=', but the modifier is reachable directly).
        $modifiers = new QueryModifiers(new SQLiteDriver());
        $this->expectException(\InvalidArgumentException::class);
        $modifiers->having('id', '; DROP TABLE items', 1);
    }

    public function test_json_path_with_injection_is_rejected(): void
    {
        $where = new WhereClause(new SQLiteDriver());
        $this->expectException(\InvalidArgumentException::class);
        $where->whereJsonContains('data', 'v', "x') OR 1=1 -- ");
    }

    public function test_wrap_identifier_doubles_embedded_quote_mysql(): void
    {
        self::assertSame('`a``b`', (new MySQLDriver())->wrapIdentifier('a`b'));
    }

    public function test_wrap_identifier_doubles_embedded_quote_pgsql(): void
    {
        self::assertSame('"a""b"', (new PostgreSQLDriver())->wrapIdentifier('a"b'));
    }

    private function sqlite(): Connection
    {
        $conn = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
        $conn->getSchemaBuilder()->createTable('items', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
        });
        return $conn;
    }
}
