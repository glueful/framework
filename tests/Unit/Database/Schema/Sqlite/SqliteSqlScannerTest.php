<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Schema\Sqlite;

use Glueful\Database\Schema\Sqlite\SqliteSqlScanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SqliteSqlScannerTest extends TestCase
{
    private SqliteSqlScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new SqliteSqlScanner();
    }

    #[Test]
    public function extractsColumnLevelAndTableLevelChecks(): void
    {
        $sql = <<<'SQL'
        CREATE TABLE "orders" (
          "id" INTEGER PRIMARY KEY AUTOINCREMENT,
          "status" TEXT NOT NULL CHECK ("status" IN ('draft', 'sent')),
          "qty" INTEGER NOT NULL,
          "price" REAL,
          CHECK ("qty" > 0 AND "price" >= 0)
        )
        SQL;

        $checks = $this->scanner->extractChecks($sql);

        $this->assertCount(2, $checks);
        $this->assertSame('"status" IN (\'draft\', \'sent\')', $checks[0]['expression']);
        $this->assertSame(['status'], $checks[0]['identifiers']);
        $this->assertSame('column', $checks[0]['scope']);
        $this->assertSame('status', $checks[0]['column']);
        $this->assertSame('"qty" > 0 AND "price" >= 0', $checks[1]['expression']);
        $this->assertSame(['qty', 'price'], $checks[1]['identifiers']);
        $this->assertSame('table', $checks[1]['scope']);
        $this->assertNull($checks[1]['column']);
    }

    #[Test]
    public function checkScannerSurvivesQuotesCommentsAndNesting(): void
    {
        $sql = <<<'SQL'
        CREATE TABLE t (
          a TEXT CHECK (a NOT IN ('it''s', 'we(ird)', 'x -- not a comment')), -- real comment CHECK (bogus)
          /* CHECK (also bogus) */
          b INTEGER CHECK ((b + 1) > (0))
        )
        SQL;

        $checks = $this->scanner->extractChecks($sql);

        $this->assertCount(2, $checks);
        $this->assertStringContainsString("'it''s'", $checks[0]['expression']);
        $this->assertSame(['a'], $checks[0]['identifiers']);
        $this->assertSame('(b + 1) > (0)', $checks[1]['expression']);
        $this->assertSame(['b'], $checks[1]['identifiers']);
    }

    #[Test]
    public function unterminatedConstructsThrow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->scanner->extractChecks("CREATE TABLE t (a TEXT CHECK (a IN ('unterminated))");
    }

    #[Test]
    public function ownershipDoesNotDependOnIdentifierCount(): void
    {
        $checks = $this->scanner->extractChecks(<<<'SQL'
        CREATE TABLE t (
          a INTEGER CHECK (a < b),
          b INTEGER,
          CHECK (a > 0)
        )
        SQL);

        $this->assertSame('column', $checks[0]['scope']);
        $this->assertSame('a', $checks[0]['column']);
        $this->assertSame(['a', 'b'], $checks[0]['identifiers']);
        $this->assertSame('table', $checks[1]['scope']);
        $this->assertNull($checks[1]['column']);
        $this->assertSame(['a'], $checks[1]['identifiers']);
    }

    #[Test]
    public function checkScannerHandlesCommentsAndBracketIdentifiersInsideExpression(): void
    {
        $checks = $this->scanner->extractChecks(
            'CREATE TABLE t ([a] INTEGER CHECK ([a] /* ) ignored */ > (0)))'
        );

        $this->assertSame(['a'], $checks[0]['identifiers']);
        $this->assertSame('column', $checks[0]['scope']);
    }

    #[Test]
    public function identifiersSkipsLiteralsCommentsAndKeywords(): void
    {
        $ids = $this->scanner->identifiers(
            'SELECT "colA", colB FROM t WHERE colB = \'not_an_id\' -- ghost_id' . "\n" . '/* ghost2 */'
        );

        $this->assertContains('cola', $ids);
        $this->assertContains('colb', $ids);
        $this->assertContains('t', $ids);
        $this->assertNotContains('not_an_id', $ids);
        $this->assertNotContains('ghost_id', $ids);
        $this->assertNotContains('ghost2', $ids);
        $this->assertNotContains('select', $ids);
        $this->assertNotContains('where', $ids);
    }

    #[Test]
    public function enumShapeRecognitionIsExact(): void
    {
        $this->assertTrue($this->scanner->isEnumCheckShape('"status" IN (\'a\', \'b\')', 'status'));
        $this->assertTrue($this->scanner->isEnumCheckShape("status IN ('a')", 'status'));
        $this->assertFalse($this->scanner->isEnumCheckShape('"status" IN (\'a\') OR 1', 'status'));
        $this->assertFalse($this->scanner->isEnumCheckShape('"other" IN (\'a\')', 'status'));
        $this->assertFalse($this->scanner->isEnumCheckShape('length("status") > 2', 'status'));
        $this->assertFalse($this->scanner->isEnumCheckShape('status IN ()', 'status'));
        $this->assertFalse($this->scanner->isEnumCheckShape('status IN (1, 2)', 'status'));
        $this->assertFalse($this->scanner->isEnumCheckShape("status IN ('a' 'b')", 'status'));
    }

    #[Test]
    public function enumRewriteRenamesOnlyTheColumn(): void
    {
        $this->assertSame(
            '"state" IN (\'a\', \'status\')',
            $this->scanner->rewriteEnumCheckColumn('"status" IN (\'a\', \'status\')', 'status', 'state')
        );
    }

    #[Test]
    public function keywordDetectionIsQuoteAware(): void
    {
        $sql = 'CREATE TABLE t (a TEXT DEFAULT \'COLLATE\', b TEXT) WITHOUT ROWID';

        $this->assertFalse($this->scanner->containsKeyword($sql, 'COLLATE'));
        $this->assertTrue($this->scanner->hasKeywordOutsideParens($sql, 'WITHOUT ROWID'));
        $this->assertFalse($this->scanner->hasKeywordOutsideParens($sql, 'STRICT'));
        $this->assertTrue($this->scanner->containsKeyword(
            'CREATE TABLE t (a TEXT COLLATE NOCASE)',
            'COLLATE'
        ));
        $this->assertFalse($this->scanner->containsKeyword(
            'CREATE TABLE t ("collate" TEXT)',
            'COLLATE'
        ));
        $this->assertTrue($this->scanner->containsUnquotedAsterisk('SELECT * FROM t'));
        $this->assertFalse($this->scanner->containsUnquotedAsterisk("SELECT '*' FROM t"));
    }
}
