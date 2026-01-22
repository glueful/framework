<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Filtering;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Glueful\Api\Filtering\Operators\OperatorRegistry;
use Glueful\Api\Filtering\Exceptions\InvalidOperatorException;

class OperatorRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        OperatorRegistry::reset();
    }

    #[Test]
    public function getReturnsOperatorByName(): void
    {
        $operator = OperatorRegistry::get('eq');

        $this->assertEquals('eq', $operator->name());
    }

    #[Test]
    public function getReturnsOperatorByAlias(): void
    {
        $operator = OperatorRegistry::get('=');

        $this->assertEquals('eq', $operator->name());
    }

    #[Test]
    public function getThrowsExceptionForUnknownOperator(): void
    {
        $this->expectException(InvalidOperatorException::class);
        OperatorRegistry::get('unknown_operator');
    }

    #[Test]
    public function hasReturnsTrueForExistingOperator(): void
    {
        $this->assertTrue(OperatorRegistry::has('eq'));
        $this->assertTrue(OperatorRegistry::has('='));
        $this->assertTrue(OperatorRegistry::has('contains'));
    }

    #[Test]
    public function hasReturnsFalseForUnknownOperator(): void
    {
        $this->assertFalse(OperatorRegistry::has('unknown'));
    }

    #[Test]
    public function getAliasesReturnsAllAliases(): void
    {
        $aliases = OperatorRegistry::getAliases();

        $this->assertContains('eq', $aliases);
        $this->assertContains('=', $aliases);
        $this->assertContains('ne', $aliases);
        $this->assertContains('!=', $aliases);
        $this->assertContains('gt', $aliases);
        $this->assertContains('gte', $aliases);
        $this->assertContains('lt', $aliases);
        $this->assertContains('lte', $aliases);
        $this->assertContains('contains', $aliases);
        $this->assertContains('in', $aliases);
        $this->assertContains('between', $aliases);
        $this->assertContains('null', $aliases);
        $this->assertContains('not_null', $aliases);
    }

    #[Test]
    public function getOperatorNamesReturnsUniqueNames(): void
    {
        $names = OperatorRegistry::getOperatorNames();

        // Should have unique operator names (not aliases)
        $this->assertContains('eq', $names);
        $this->assertContains('ne', $names);
        $this->assertContains('gt', $names);
        $this->assertContains('gte', $names);
        $this->assertContains('lt', $names);
        $this->assertContains('lte', $names);
        $this->assertContains('contains', $names);
        $this->assertContains('starts', $names);
        $this->assertContains('ends', $names);
        $this->assertContains('in', $names);
        $this->assertContains('nin', $names);
        $this->assertContains('between', $names);
        $this->assertContains('null', $names);
        $this->assertContains('not_null', $names);
    }

    #[Test]
    #[DataProvider('operatorAliasProvider')]
    public function operatorsHaveCorrectAliases(string $name, array $expectedAliases): void
    {
        foreach ($expectedAliases as $alias) {
            $operator = OperatorRegistry::get($alias);
            $this->assertEquals($name, $operator->name());
        }
    }

    /**
     * @return array<string, array{0: string, 1: array<string>}>
     */
    public static function operatorAliasProvider(): array
    {
        return [
            'equal' => ['eq', ['eq', '=', 'equal', 'equals']],
            'not equal' => ['ne', ['ne', '!=', 'neq', 'not_equal']],
            'greater than' => ['gt', ['gt', '>', 'greater_than']],
            'greater than or equal' => ['gte', ['gte', '>=', 'greater_than_or_equal']],
            'less than' => ['lt', ['lt', '<', 'less_than']],
            'less than or equal' => ['lte', ['lte', '<=', 'less_than_or_equal']],
            'contains' => ['contains', ['contains', 'like', 'includes']],
            'starts with' => ['starts', ['starts', 'prefix', 'starts_with']],
            'ends with' => ['ends', ['ends', 'suffix', 'ends_with']],
            'in' => ['in', ['in']],
            'not in' => ['nin', ['nin', 'not_in']],
            'between' => ['between', ['between', 'range']],
            'null' => ['null', ['null', 'is_null']],
            'not null' => ['not_null', ['not_null', 'notnull', 'is_not_null']],
        ];
    }

    #[Test]
    public function operatorNameIsCaseInsensitive(): void
    {
        $lower = OperatorRegistry::get('eq');
        $upper = OperatorRegistry::get('EQ');
        $mixed = OperatorRegistry::get('Eq');

        $this->assertEquals($lower->name(), $upper->name());
        $this->assertEquals($lower->name(), $mixed->name());
    }
}
