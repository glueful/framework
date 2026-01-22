<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Filtering;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Glueful\Api\Filtering\ParsedFilter;

class ParsedFilterTest extends TestCase
{
    #[Test]
    public function createReturnsNewInstance(): void
    {
        $filter = ParsedFilter::create('status', 'eq', 'active');

        $this->assertEquals('status', $filter->field);
        $this->assertEquals('eq', $filter->operator);
        $this->assertEquals('active', $filter->value);
    }

    #[Test]
    public function equalsCreatesEqualityFilter(): void
    {
        $filter = ParsedFilter::equals('name', 'John');

        $this->assertEquals('name', $filter->field);
        $this->assertEquals('eq', $filter->operator);
        $this->assertEquals('John', $filter->value);
    }

    #[Test]
    public function hasOperatorReturnsTrueForMatchingOperator(): void
    {
        $filter = ParsedFilter::create('age', 'gte', 18);

        $this->assertTrue($filter->hasOperator('gte'));
        $this->assertFalse($filter->hasOperator('eq'));
    }

    #[Test]
    public function isForFieldReturnsTrueForMatchingField(): void
    {
        $filter = ParsedFilter::create('status', 'eq', 'active');

        $this->assertTrue($filter->isForField('status'));
        $this->assertFalse($filter->isForField('role'));
    }

    #[Test]
    public function getValueAsArrayReturnsArrayForArrayValue(): void
    {
        $filter = ParsedFilter::create('status', 'in', ['active', 'pending']);

        $this->assertEquals(['active', 'pending'], $filter->getValueAsArray());
    }

    #[Test]
    public function getValueAsArrayParsesCommaSeparatedString(): void
    {
        $filter = ParsedFilter::create('status', 'in', 'active,pending,review');

        $this->assertEquals(['active', 'pending', 'review'], $filter->getValueAsArray());
    }

    #[Test]
    public function getValueAsArrayWrapsScalarValueInArray(): void
    {
        $filter = ParsedFilter::create('status', 'eq', 'active');

        $this->assertEquals(['active'], $filter->getValueAsArray());
    }

    #[Test]
    public function filterIsImmutable(): void
    {
        $filter = new ParsedFilter('status', 'eq', 'active');

        // Properties are readonly, so this should be the same instance
        $this->assertEquals('status', $filter->field);
        $this->assertEquals('eq', $filter->operator);
        $this->assertEquals('active', $filter->value);
    }
}
