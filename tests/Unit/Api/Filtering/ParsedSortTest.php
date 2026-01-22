<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Filtering;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Glueful\Api\Filtering\ParsedSort;

class ParsedSortTest extends TestCase
{
    #[Test]
    public function createReturnsNewInstance(): void
    {
        $sort = ParsedSort::create('name', 'ASC');

        $this->assertEquals('name', $sort->field);
        $this->assertEquals('ASC', $sort->direction);
    }

    #[Test]
    public function ascCreatesAscendingSort(): void
    {
        $sort = ParsedSort::asc('name');

        $this->assertEquals('name', $sort->field);
        $this->assertEquals('ASC', $sort->direction);
    }

    #[Test]
    public function descCreatesDescendingSort(): void
    {
        $sort = ParsedSort::desc('created_at');

        $this->assertEquals('created_at', $sort->field);
        $this->assertEquals('DESC', $sort->direction);
    }

    #[Test]
    public function fromStringParsesDescendingSort(): void
    {
        $sort = ParsedSort::fromString('-created_at');

        $this->assertEquals('created_at', $sort->field);
        $this->assertEquals('DESC', $sort->direction);
    }

    #[Test]
    public function fromStringParsesAscendingSort(): void
    {
        $sort = ParsedSort::fromString('name');

        $this->assertEquals('name', $sort->field);
        $this->assertEquals('ASC', $sort->direction);
    }

    #[Test]
    public function fromStringParsesExplicitAscendingSort(): void
    {
        $sort = ParsedSort::fromString('+name');

        $this->assertEquals('name', $sort->field);
        $this->assertEquals('ASC', $sort->direction);
    }

    #[Test]
    public function fromStringTrimsWhitespace(): void
    {
        $sort = ParsedSort::fromString('  -created_at  ');

        $this->assertEquals('created_at', $sort->field);
        $this->assertEquals('DESC', $sort->direction);
    }

    #[Test]
    public function isAscendingReturnsTrueForAscSort(): void
    {
        $sort = ParsedSort::asc('name');

        $this->assertTrue($sort->isAscending());
        $this->assertFalse($sort->isDescending());
    }

    #[Test]
    public function isDescendingReturnsTrueForDescSort(): void
    {
        $sort = ParsedSort::desc('created_at');

        $this->assertTrue($sort->isDescending());
        $this->assertFalse($sort->isAscending());
    }

    #[Test]
    public function toStringReturnsCorrectFormat(): void
    {
        $ascSort = ParsedSort::asc('name');
        $descSort = ParsedSort::desc('created_at');

        $this->assertEquals('name', $ascSort->toString());
        $this->assertEquals('-created_at', $descSort->toString());
    }

    #[Test]
    public function defaultDirectionIsAscending(): void
    {
        $sort = new ParsedSort('name');

        $this->assertEquals('ASC', $sort->direction);
    }
}
