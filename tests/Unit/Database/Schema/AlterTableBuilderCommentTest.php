<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Schema;

use Glueful\Database\Schema\Builders\AlterTableBuilder;
use Glueful\Database\Schema\DTOs\TableDefinition;
use Glueful\Database\Schema\Exceptions\UnsupportedSchemaOperationException;
use Glueful\Database\Schema\Generators\SQLiteSqlGenerator;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AlterTableBuilder is the other public alter seam; its comment() change key
 * was silently ignored by every generator. It now fails closed.
 */
final class AlterTableBuilderCommentTest extends TestCase
{
    private function builder(SchemaBuilderInterface $schemaBuilder): AlterTableBuilder
    {
        return new AlterTableBuilder(
            $schemaBuilder,
            new SQLiteSqlGenerator(),
            'users',
            new TableDefinition(name: 'users')
        );
    }

    #[Test]
    public function commentAlterationFailsClosedBeforeAnyOperationIsQueued(): void
    {
        $schemaBuilder = $this->createMock(SchemaBuilderInterface::class);
        $schemaBuilder->expects($this->never())->method('addPendingOperation');
        $schemaBuilder->expects($this->never())->method('execute');

        $builder = $this->builder($schemaBuilder);
        $builder->comment('a table comment');

        try {
            $builder->execute();
            $this->fail('Expected the comment alteration to fail closed');
        } catch (UnsupportedSchemaOperationException $e) {
            $this->assertSame('users', $e->table());
            $this->assertSame('comment', $e->operation());
            $this->assertStringContainsString('silently', $e->getMessage());
        }
    }

    #[Test]
    public function executeWithoutACommentStillWorks(): void
    {
        $schemaBuilder = $this->createMock(SchemaBuilderInterface::class);
        $schemaBuilder->method('execute')->willReturn([0]);

        $builder = $this->builder($schemaBuilder);
        $builder->dropIndex('users_email_index');

        $this->assertTrue($builder->execute());
    }
}
