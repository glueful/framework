<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Query;

use Glueful\Database\Query\QueryState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueryState::class)]
final class QueryStateTest extends TestCase
{
    public function testSelectRawBindingsStartEmpty(): void
    {
        $state = new QueryState();

        $this->assertSame([], $state->getSelectRawBindings());
    }

    public function testAppendSelectRawBindingsPreservesOrder(): void
    {
        $state = new QueryState();

        $state->appendSelectRawBindings([1, 'a']);
        $state->appendSelectRawBindings([true]);

        $this->assertSame([1, 'a', true], $state->getSelectRawBindings());
    }

    public function testResetClearsSelectRawBindings(): void
    {
        $state = new QueryState();
        $state->appendSelectRawBindings([1, 2]);

        $state->reset();

        $this->assertSame([], $state->getSelectRawBindings());
    }

    public function testClearSelectRawBindings(): void
    {
        $state = new QueryState();
        $state->appendSelectRawBindings([1, 2]);

        $state->clearSelectRawBindings();

        $this->assertSame([], $state->getSelectRawBindings());
    }

    public function testCloneCopiesSelectRawBindingsAndIsolatesMutations(): void
    {
        $state = new QueryState();
        $state->appendSelectRawBindings([5]);

        $clone = $state->clone();
        $clone->appendSelectRawBindings([6]);

        $this->assertSame([5], $state->getSelectRawBindings());
        $this->assertSame([5, 6], $clone->getSelectRawBindings());
    }
}
