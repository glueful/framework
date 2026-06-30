<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Validation;

use Glueful\Validation\Rules\CastToBoolean;
use Glueful\Validation\Rules\CastToDate;
use Glueful\Validation\Rules\CastToInt;
use PHPUnit\Framework\TestCase;

final class CastRulesTest extends TestCase
{
    public function test_cast_to_int_coerces_int_like_values(): void
    {
        $rule = new CastToInt();
        self::assertSame(42, $rule->mutate('42'));
        self::assertSame(42, $rule->mutate(42));
        self::assertSame(-7, $rule->mutate(' -7 '));
        self::assertSame(5, $rule->mutate(5.0));
    }

    public function test_cast_to_int_leaves_non_int_unchanged(): void
    {
        $rule = new CastToInt();
        self::assertSame('abc', $rule->mutate('abc'));
        self::assertSame('42.5', $rule->mutate('42.5'));
        self::assertSame(3.5, $rule->mutate(3.5));
        self::assertNull($rule->mutate(null));
    }

    public function test_cast_to_boolean_coerces_bool_like_values(): void
    {
        $rule = new CastToBoolean();
        foreach (['true', '1', 'yes', 'on', 'TRUE', ' On '] as $truthy) {
            self::assertTrue($rule->mutate($truthy), $truthy);
        }
        foreach (['false', '0', 'no', 'off'] as $falsy) {
            self::assertFalse($rule->mutate($falsy), $falsy);
        }
        self::assertTrue($rule->mutate(true));
        self::assertTrue($rule->mutate(1));
        self::assertFalse($rule->mutate(0));
    }

    public function test_cast_to_boolean_leaves_ambiguous_unchanged(): void
    {
        $rule = new CastToBoolean();
        self::assertSame('maybe', $rule->mutate('maybe'));
        self::assertSame('', $rule->mutate(''));
        self::assertSame(2, $rule->mutate(2));
    }

    public function test_cast_to_date_normalizes_to_format(): void
    {
        self::assertSame('2024-02-29 00:00:00', (new CastToDate())->mutate('2024-02-29'));
        self::assertSame('2024-02-29', (new CastToDate('Y-m-d'))->mutate('29 Feb 2024'));
    }

    public function test_cast_to_date_leaves_unparseable_unchanged(): void
    {
        $rule = new CastToDate();
        self::assertSame('not-a-date', $rule->mutate('not-a-date'));
        self::assertSame('', $rule->mutate(''));
        self::assertNull($rule->mutate(null));
    }
}
