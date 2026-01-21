<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Glueful\Validation\Support\RuleParser;
use Glueful\Validation\Rules\Required;
use Glueful\Validation\Rules\Email;
use Glueful\Validation\Rules\Length;
use Glueful\Validation\Rules\Type;
use Glueful\Validation\Rules\InArray;
use Glueful\Validation\Rules\Confirmed;
use Glueful\Validation\Rules\Date;
use Glueful\Validation\Rules\Before;
use Glueful\Validation\Rules\After;
use Glueful\Validation\Rules\Url;
use Glueful\Validation\Rules\Uuid;
use Glueful\Validation\Rules\Nullable;
use Glueful\Validation\Rules\Sometimes;

class RuleParserTest extends TestCase
{
    private RuleParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RuleParser();
    }

    public function testParsesRequiredRule(): void
    {
        $rules = $this->parser->parse(['email' => 'required']);

        $this->assertCount(1, $rules['email']);
        $this->assertInstanceOf(Required::class, $rules['email'][0]);
    }

    public function testParsesEmailRule(): void
    {
        $rules = $this->parser->parse(['email' => 'email']);

        $this->assertCount(1, $rules['email']);
        $this->assertInstanceOf(Email::class, $rules['email'][0]);
    }

    public function testParsesMultipleRules(): void
    {
        $rules = $this->parser->parse(['email' => 'required|email']);

        $this->assertCount(2, $rules['email']);
        $this->assertInstanceOf(Required::class, $rules['email'][0]);
        $this->assertInstanceOf(Email::class, $rules['email'][1]);
    }

    public function testParsesMinRule(): void
    {
        $rules = $this->parser->parse(['password' => 'min:8']);

        $this->assertCount(1, $rules['password']);
        $this->assertInstanceOf(Length::class, $rules['password'][0]);
    }

    public function testParsesMaxRule(): void
    {
        $rules = $this->parser->parse(['name' => 'max:255']);

        $this->assertCount(1, $rules['name']);
        $this->assertInstanceOf(Length::class, $rules['name'][0]);
    }

    public function testParsesStringTypeRule(): void
    {
        $rules = $this->parser->parse(['name' => 'string']);

        $this->assertCount(1, $rules['name']);
        $this->assertInstanceOf(Type::class, $rules['name'][0]);
    }

    public function testParsesIntegerTypeRule(): void
    {
        $rules = $this->parser->parse(['age' => 'integer']);

        $this->assertCount(1, $rules['age']);
        $this->assertInstanceOf(Type::class, $rules['age'][0]);
    }

    public function testParsesInRule(): void
    {
        $rules = $this->parser->parse(['status' => 'in:active,inactive,pending']);

        $this->assertCount(1, $rules['status']);
        $this->assertInstanceOf(InArray::class, $rules['status'][0]);
    }

    public function testParsesConfirmedRule(): void
    {
        $rules = $this->parser->parse(['password' => 'confirmed']);

        $this->assertCount(1, $rules['password']);
        $this->assertInstanceOf(Confirmed::class, $rules['password'][0]);
    }

    public function testParsesDateRule(): void
    {
        $rules = $this->parser->parse(['birthday' => 'date']);

        $this->assertCount(1, $rules['birthday']);
        $this->assertInstanceOf(Date::class, $rules['birthday'][0]);
    }

    public function testParsesDateRuleWithFormat(): void
    {
        $rules = $this->parser->parse(['birthday' => 'date:Y-m-d']);

        $this->assertCount(1, $rules['birthday']);
        $this->assertInstanceOf(Date::class, $rules['birthday'][0]);
    }

    public function testParsesBeforeRule(): void
    {
        $rules = $this->parser->parse(['start_date' => 'before:today']);

        $this->assertCount(1, $rules['start_date']);
        $this->assertInstanceOf(Before::class, $rules['start_date'][0]);
    }

    public function testParsesAfterRule(): void
    {
        $rules = $this->parser->parse(['end_date' => 'after:today']);

        $this->assertCount(1, $rules['end_date']);
        $this->assertInstanceOf(After::class, $rules['end_date'][0]);
    }

    public function testParsesUrlRule(): void
    {
        $rules = $this->parser->parse(['website' => 'url']);

        $this->assertCount(1, $rules['website']);
        $this->assertInstanceOf(Url::class, $rules['website'][0]);
    }

    public function testParsesUuidRule(): void
    {
        $rules = $this->parser->parse(['id' => 'uuid']);

        $this->assertCount(1, $rules['id']);
        $this->assertInstanceOf(Uuid::class, $rules['id'][0]);
    }

    public function testParsesNullableRule(): void
    {
        $rules = $this->parser->parse(['middle_name' => 'nullable|string']);

        $this->assertCount(2, $rules['middle_name']);
        $this->assertInstanceOf(Nullable::class, $rules['middle_name'][0]);
        $this->assertInstanceOf(Type::class, $rules['middle_name'][1]);
    }

    public function testParsesSometimesRule(): void
    {
        $rules = $this->parser->parse(['avatar' => 'sometimes|string']);

        $this->assertCount(2, $rules['avatar']);
        $this->assertInstanceOf(Sometimes::class, $rules['avatar'][0]);
        $this->assertInstanceOf(Type::class, $rules['avatar'][1]);
    }

    public function testParsesComplexRules(): void
    {
        $rules = $this->parser->parse([
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
            'name' => 'required|string|max:255',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $this->assertCount(2, $rules['email']);
        $this->assertCount(3, $rules['password']);
        $this->assertCount(3, $rules['name']);
        $this->assertCount(2, $rules['status']);
    }

    public function testParsesRuleObjectsArray(): void
    {
        $ruleObjects = [new Required(), new Email()];
        $rules = $this->parser->parse(['email' => $ruleObjects]);

        $this->assertCount(2, $rules['email']);
        $this->assertSame($ruleObjects[0], $rules['email'][0]);
        $this->assertSame($ruleObjects[1], $rules['email'][1]);
    }

    public function testParseMultipleFields(): void
    {
        $rules = $this->parser->parse([
            'email' => 'required|email',
            'name' => 'required|string',
        ]);

        $this->assertArrayHasKey('email', $rules);
        $this->assertArrayHasKey('name', $rules);
    }

    public function testExtendWithCustomRule(): void
    {
        $this->parser->extend('custom', Required::class);

        $this->assertTrue($this->parser->hasRule('custom'));
    }

    public function testGetRuleNames(): void
    {
        $names = $this->parser->getRuleNames();

        $this->assertContains('required', $names);
        $this->assertContains('email', $names);
        $this->assertContains('string', $names);
        $this->assertContains('confirmed', $names);
    }
}
