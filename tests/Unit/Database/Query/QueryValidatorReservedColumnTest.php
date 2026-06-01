<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Query;

use Glueful\Database\Features\QueryValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Column identifiers are always emitted through the driver's wrapIdentifier()
 * (e.g. `from` / "from"), so a reserved word used as a *column* name is valid SQL.
 * The validator must therefore accept reserved words as column names, while still
 * rejecting them as unquoted table/schema/alias names.
 */
#[CoversClass(QueryValidator::class)]
final class QueryValidatorReservedColumnTest extends TestCase
{
    public function testReservedWordIsAllowedAsColumnName(): void
    {
        $validator = new QueryValidator();
        $this->assertTrue($validator->isStrictMode(), 'strict mode is on by default');

        // No exception => reserved words like FROM/ORDER/GROUP are valid column names.
        $validator->validateColumnNames(['from', 'to', 'order', 'group', 'key', 'values']);
        $this->addToAssertionCount(1);
    }

    public function testInsertWithReservedColumnNamePasses(): void
    {
        $validator = new QueryValidator();

        // Previously threw "Column name 'from' is a reserved SQL keyword".
        $validator->validateInsert('conversa_messages', [
            'to' => '+15551234567',
            'from' => '+15550000000',
            'status' => 'queued',
        ]);
        $this->addToAssertionCount(1);
    }

    public function testReservedWordStillRejectedAsTableName(): void
    {
        $validator = new QueryValidator();

        $this->expectException(\InvalidArgumentException::class);
        $validator->validateTableName('from');
    }

    public function testSqlInjectionCharactersInColumnStillRejected(): void
    {
        $validator = new QueryValidator();

        $this->expectException(\InvalidArgumentException::class);
        $validator->validateColumnNames(['from"; DROP TABLE x; --']);
    }
}
