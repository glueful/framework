<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Glueful\Validation\Rules\Confirmed;
use Glueful\Validation\Rules\Date;
use Glueful\Validation\Rules\Before;
use Glueful\Validation\Rules\After;
use Glueful\Validation\Rules\Url;
use Glueful\Validation\Rules\Uuid;
use Glueful\Validation\Rules\Nullable;
use Glueful\Validation\Rules\Sometimes;
use Glueful\Validation\Rules\Json;
use Glueful\Validation\Rules\Dimensions;

class ValidationRulesTest extends TestCase
{
    public function testConfirmedRulePassesWhenMatches(): void
    {
        $rule = new Confirmed();
        $context = [
            'field' => 'password',
            'data' => [
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
            ],
        ];

        $result = $rule->validate('secret123', $context);
        $this->assertNull($result);
    }

    public function testConfirmedRuleFailsWhenNotMatching(): void
    {
        $rule = new Confirmed();
        $context = [
            'field' => 'password',
            'data' => [
                'password' => 'secret123',
                'password_confirmation' => 'different',
            ],
        ];

        $result = $rule->validate('secret123', $context);
        $this->assertNotNull($result);
        $this->assertStringContainsString('confirmation does not match', $result);
    }

    public function testConfirmedRuleSkipsNullValue(): void
    {
        $rule = new Confirmed();
        $result = $rule->validate(null, []);
        $this->assertNull($result);
    }

    public function testDateRulePassesValidDate(): void
    {
        $rule = new Date();
        $result = $rule->validate('2024-01-15', ['field' => 'date']);
        $this->assertNull($result);
    }

    public function testDateRuleFailsInvalidDate(): void
    {
        $rule = new Date();
        $result = $rule->validate('not-a-date', ['field' => 'date']);
        $this->assertNotNull($result);
    }

    public function testDateRuleWithFormatPassesCorrectFormat(): void
    {
        $rule = new Date('Y-m-d');
        $result = $rule->validate('2024-01-15', ['field' => 'date']);
        $this->assertNull($result);
    }

    public function testDateRuleWithFormatFailsIncorrectFormat(): void
    {
        $rule = new Date('Y-m-d');
        $result = $rule->validate('15/01/2024', ['field' => 'date']);
        $this->assertNotNull($result);
    }

    public function testBeforeRulePassesWhenBefore(): void
    {
        $rule = new Before('2030-01-01');
        $result = $rule->validate('2025-06-15', ['field' => 'date']);
        $this->assertNull($result);
    }

    public function testBeforeRuleFailsWhenNotBefore(): void
    {
        $rule = new Before('2020-01-01');
        $result = $rule->validate('2025-06-15', ['field' => 'date']);
        $this->assertNotNull($result);
    }

    public function testBeforeRuleWithToday(): void
    {
        $rule = new Before('today');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $result = $rule->validate($yesterday, ['field' => 'date']);
        $this->assertNull($result);
    }

    public function testAfterRulePassesWhenAfter(): void
    {
        $rule = new After('2020-01-01');
        $result = $rule->validate('2025-06-15', ['field' => 'date']);
        $this->assertNull($result);
    }

    public function testAfterRuleFailsWhenNotAfter(): void
    {
        $rule = new After('2030-01-01');
        $result = $rule->validate('2025-06-15', ['field' => 'date']);
        $this->assertNotNull($result);
    }

    public function testUrlRulePassesValidUrl(): void
    {
        $rule = new Url();
        $result = $rule->validate('https://example.com', ['field' => 'website']);
        $this->assertNull($result);
    }

    public function testUrlRuleFailsInvalidUrl(): void
    {
        $rule = new Url();
        $result = $rule->validate('not-a-url', ['field' => 'website']);
        $this->assertNotNull($result);
    }

    public function testUrlRuleWithProtocolRestriction(): void
    {
        $rule = new Url(['https']);
        $result = $rule->validate('http://example.com', ['field' => 'website']);
        $this->assertNotNull($result);
        $this->assertStringContainsString('protocols', $result);
    }

    public function testUuidRulePassesValidUuid(): void
    {
        $rule = new Uuid();
        $result = $rule->validate('550e8400-e29b-41d4-a716-446655440000', ['field' => 'id']);
        $this->assertNull($result);
    }

    public function testUuidRuleFailsInvalidUuid(): void
    {
        $rule = new Uuid();
        $result = $rule->validate('not-a-uuid', ['field' => 'id']);
        $this->assertNotNull($result);
    }

    public function testUuidRuleRejectsNilByDefault(): void
    {
        $rule = new Uuid();
        $result = $rule->validate('00000000-0000-0000-0000-000000000000', ['field' => 'id']);
        $this->assertNotNull($result);
    }

    public function testUuidRuleAllowsNilWhenEnabled(): void
    {
        $rule = new Uuid(allowNil: true);
        $result = $rule->validate('00000000-0000-0000-0000-000000000000', ['field' => 'id']);
        $this->assertNull($result);
    }

    public function testNullableRuleNeverFails(): void
    {
        $rule = new Nullable();
        $this->assertNull($rule->validate(null, []));
        $this->assertNull($rule->validate('value', []));
        $this->assertNull($rule->validate('', []));
    }

    public function testNullableRuleAllowsNull(): void
    {
        $rule = new Nullable();
        $this->assertTrue($rule->allowsNull());
    }

    public function testNullableShouldStopValidation(): void
    {
        $rule = new Nullable();
        $this->assertTrue($rule->shouldStopValidation(null));
        $this->assertTrue($rule->shouldStopValidation(''));
        $this->assertFalse($rule->shouldStopValidation('value'));
    }

    public function testSometimesRuleNeverFails(): void
    {
        $rule = new Sometimes();
        $this->assertNull($rule->validate(null, []));
        $this->assertNull($rule->validate('value', []));
    }

    public function testSometimesRuleIsOptional(): void
    {
        $rule = new Sometimes();
        $this->assertTrue($rule->isOptional());
    }

    public function testSometimesShouldSkipValidation(): void
    {
        $rule = new Sometimes();
        $this->assertTrue($rule->shouldSkipValidation('field', []));
        $this->assertFalse($rule->shouldSkipValidation('field', ['field' => 'value']));
        $this->assertFalse($rule->shouldSkipValidation('field', ['field' => null]));
    }

    // Json Rule Tests
    public function testJsonRulePassesValidJson(): void
    {
        $rule = new Json();
        $result = $rule->validate('{"key": "value"}', ['field' => 'data']);
        $this->assertNull($result);
    }

    public function testJsonRulePassesValidJsonArray(): void
    {
        $rule = new Json();
        $result = $rule->validate('[1, 2, 3]', ['field' => 'data']);
        $this->assertNull($result);
    }

    public function testJsonRuleFailsInvalidJson(): void
    {
        $rule = new Json();
        $result = $rule->validate('{invalid json}', ['field' => 'data']);
        $this->assertNotNull($result);
        $this->assertStringContainsString('valid JSON', $result);
    }

    public function testJsonRuleFailsNonString(): void
    {
        $rule = new Json();
        $result = $rule->validate(['array'], ['field' => 'data']);
        $this->assertNotNull($result);
    }

    public function testJsonRuleSkipsNullValue(): void
    {
        $rule = new Json();
        $result = $rule->validate(null, ['field' => 'data']);
        $this->assertNull($result);
    }

    // Dimensions Rule Tests
    public function testDimensionsRuleSkipsNullValue(): void
    {
        $rule = new Dimensions(['min_width' => 100]);
        $result = $rule->validate(null, ['field' => 'image']);
        $this->assertNull($result);
    }

    public function testDimensionsRuleFailsNonImageFile(): void
    {
        $rule = new Dimensions(['min_width' => 100]);
        // Use a non-existent path
        $result = $rule->validate('/nonexistent/path.jpg', ['field' => 'image']);
        $this->assertNotNull($result);
    }
}
