<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Support\Documentation\ValidationRuleSchema;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Glueful\Support\Documentation\ValidationRuleSchema
 */
final class ValidationRuleSchemaTest extends TestCase
{
    public function testRequiredRulePopulatesRequiredList(): void
    {
        $schema = ValidationRuleSchema::toObjectSchema([
            'email' => 'required|email',
            'nickname' => 'string',
        ]);

        self::assertSame('object', $schema['type']);
        self::assertSame(['email'], $schema['required']);
        self::assertArrayHasKey('nickname', $schema['properties']);
    }

    public function testNoRequiredFieldsOmitsRequiredKey(): void
    {
        $schema = ValidationRuleSchema::toObjectSchema([
            'nickname' => 'string',
        ]);

        self::assertArrayNotHasKey('required', $schema);
    }

    public function testTypeRulesMapToOpenApiTypes(): void
    {
        $schema = ValidationRuleSchema::toObjectSchema([
            'a' => 'string',
            'b' => 'integer',
            'c' => 'int',
            'd' => 'numeric',
            'e' => 'boolean',
            'f' => 'bool',
            'g' => 'array',
        ]);

        $props = $schema['properties'];
        self::assertSame('string', $props['a']['type']);
        self::assertSame('integer', $props['b']['type']);
        self::assertSame('integer', $props['c']['type']);
        self::assertSame('number', $props['d']['type']);
        self::assertSame('boolean', $props['e']['type']);
        self::assertSame('boolean', $props['f']['type']);
        self::assertSame('array', $props['g']['type']);
        self::assertSame(['type' => 'string'], $props['g']['items']);
    }

    public function testFormatRulesMapToStringFormats(): void
    {
        $schema = ValidationRuleSchema::toObjectSchema([
            'email' => 'email',
            'id' => 'uuid',
            'website' => 'url',
            'born_on' => 'date',
            'seen_at' => 'datetime',
            'starts' => 'date_format:Y-m-d H:i:s',
        ]);

        $props = $schema['properties'];
        self::assertSame(['type' => 'string', 'format' => 'email'], $props['email']);
        self::assertSame(['type' => 'string', 'format' => 'uuid'], $props['id']);
        self::assertSame(['type' => 'string', 'format' => 'uri'], $props['website']);
        self::assertSame(['type' => 'string', 'format' => 'date'], $props['born_on']);
        self::assertSame(['type' => 'string', 'format' => 'date-time'], $props['seen_at']);
        self::assertSame(['type' => 'string', 'format' => 'date-time'], $props['starts']);
    }

    public function testInRuleProducesEnum(): void
    {
        $schema = ValidationRuleSchema::toObjectSchema([
            'role' => 'required|in:admin,user,guest',
        ]);

        $role = $schema['properties']['role'];
        self::assertSame('string', $role['type']);
        self::assertSame(['admin', 'user', 'guest'], $role['enum']);
    }

    public function testMinMaxOnStringUseLength(): void
    {
        $schema = ValidationRuleSchema::toObjectSchema([
            'name' => 'string|min:1|max:255',
            // No explicit type -> defaults to string -> length constraints.
            'slug' => 'min:3|max:50',
        ]);

        $name = $schema['properties']['name'];
        self::assertSame('string', $name['type']);
        self::assertSame(1, $name['minLength']);
        self::assertSame(255, $name['maxLength']);

        $slug = $schema['properties']['slug'];
        self::assertSame('string', $slug['type']);
        self::assertSame(3, $slug['minLength']);
        self::assertSame(50, $slug['maxLength']);
    }

    public function testMinMaxOnNumericUseMinimumMaximum(): void
    {
        $schema = ValidationRuleSchema::toObjectSchema([
            'age' => 'integer|min:18|max:120',
            'price' => 'numeric|min:0|max:999',
        ]);

        $age = $schema['properties']['age'];
        self::assertSame('integer', $age['type']);
        self::assertSame(18, $age['minimum']);
        self::assertSame(120, $age['maximum']);
        self::assertArrayNotHasKey('minLength', $age);

        $price = $schema['properties']['price'];
        self::assertSame('number', $price['type']);
        self::assertSame(0, $price['minimum']);
        self::assertSame(999, $price['maximum']);
    }

    public function testArrayInputPerFieldIsAccepted(): void
    {
        $schema = ValidationRuleSchema::toObjectSchema([
            'email' => ['required', 'email'],
            'age' => ['integer', 'min:18'],
        ]);

        self::assertSame(['email'], $schema['required']);
        self::assertSame('email', $schema['properties']['email']['format']);
        self::assertSame(18, $schema['properties']['age']['minimum']);
    }

    public function testUnknownRulesAreIgnoredAndDefaultTypeIsString(): void
    {
        $schema = ValidationRuleSchema::toObjectSchema([
            'password' => 'required|confirmed|unique:users|nullable|sometimes',
        ]);

        $password = $schema['properties']['password'];
        self::assertSame('string', $password['type']);
        self::assertArrayNotHasKey('format', $password);
        self::assertArrayNotHasKey('enum', $password);
        self::assertSame(['password'], $schema['required']);
    }

    public function testEmptyRulesProduceEmptyObjectSchema(): void
    {
        $schema = ValidationRuleSchema::toObjectSchema([]);

        self::assertSame('object', $schema['type']);
        self::assertSame([], $schema['properties']);
        self::assertArrayNotHasKey('required', $schema);
    }

    public function testFormatRuleWinsOverPlainStringType(): void
    {
        // `email` should keep its format even when `string` also present.
        $schema = ValidationRuleSchema::toObjectSchema([
            'email' => 'string|email',
        ]);

        self::assertSame('string', $schema['properties']['email']['type']);
        self::assertSame('email', $schema['properties']['email']['format']);
    }

    public function testArrayBoundsUseItemsKeywordsNotLength(): void
    {
        $schema = ValidationRuleSchema::toObjectSchema([
            'tags' => 'array|min:1|max:5',
        ]);

        $tags = $schema['properties']['tags'];
        self::assertSame('array', $tags['type']);
        self::assertSame(1, $tags['minItems']);
        self::assertSame(5, $tags['maxItems']);
        self::assertArrayNotHasKey('minLength', $tags);
        self::assertArrayNotHasKey('maxLength', $tags);
    }

    public function testBooleanBoundsAreDropped(): void
    {
        $schema = ValidationRuleSchema::toObjectSchema([
            'active' => 'boolean|min:0|max:1',
        ]);

        $active = $schema['properties']['active'];
        self::assertSame('boolean', $active['type']);
        self::assertArrayNotHasKey('minLength', $active);
        self::assertArrayNotHasKey('minimum', $active);
        self::assertArrayNotHasKey('minItems', $active);
    }

    public function testIntegerEnumMembersAreCastToInt(): void
    {
        $schema = ValidationRuleSchema::toObjectSchema([
            'level' => 'integer|in:1,2,3',
        ]);

        $level = $schema['properties']['level'];
        self::assertSame('integer', $level['type']);
        self::assertSame([1, 2, 3], $level['enum']);
    }

    public function testStringEnumMembersStayStrings(): void
    {
        $schema = ValidationRuleSchema::toObjectSchema([
            'status' => 'in:draft,published',
        ]);

        self::assertSame(['draft', 'published'], $schema['properties']['status']['enum']);
    }
}
