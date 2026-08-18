<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions\Schema;

use Glueful\Extensions\Schema\ExtensionOperation;
use PHPUnit\Framework\TestCase;

/**
 * Regression tripwire (1.80.1): every value the executor writes into extension_operations must
 * fit its column. SQLite ignores varchar lengths, so the suite never notices an overflow — on
 * PostgreSQL it is a hard 22001 at the moment the operation record is inserted. 1.80.0 shipped
 * `protected_migrate` (17 chars) against an operation column created as string(16).
 */
final class OperationColumnWidthTest extends TestCase
{
    private function declaredWidth(string $column): int
    {
        $ddl = (string) file_get_contents(
            dirname(__DIR__, 4) . '/migrations/extensions/001_CreateExtensionOperationsTable.php'
        );
        self::assertSame(1, preg_match("/'{$column}', (\\d+)/", $ddl, $m), "column {$column} declared");
        return (int) $m[1];
    }

    public function testEveryExecutorOperationNameFitsTheColumn(): void
    {
        $width = $this->declaredWidth('operation');
        foreach (['enable', 'disable', 'protected_migrate'] as $operation) {
            self::assertLessThanOrEqual($width, strlen($operation), $operation);
        }
    }

    public function testEveryTerminalStatusFitsTheColumn(): void
    {
        $width = $this->declaredWidth('status');
        foreach (
            [
                ExtensionOperation::STATUS_RUNNING,
                ExtensionOperation::STATUS_SUCCEEDED,
                ExtensionOperation::STATUS_FAILED,
                ExtensionOperation::STATUS_MANUAL_REPAIR,
                ExtensionOperation::STATUS_CACHE_STALE,
            ] as $status
        ) {
            self::assertLessThanOrEqual($width, strlen($status), $status);
        }
    }
}
