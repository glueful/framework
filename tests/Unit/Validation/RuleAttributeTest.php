<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Validation;

use Glueful\Validation\Attributes\Rule;
use PHPUnit\Framework\TestCase;

final class RuleAttributeTest extends TestCase
{
    public function testRuleAttributeHoldsItsRuleString(): void
    {
        $r = new Rule('required|email');
        self::assertSame('required|email', $r->rules);
    }

    public function testRuleAttributeTargetsPropertiesAndParameters(): void
    {
        $ref = new \ReflectionClass(Rule::class);
        $attr = $ref->getAttributes(\Attribute::class)[0]->newInstance();
        self::assertSame(
            \Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER,
            $attr->flags & (\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)
        );
    }
}
