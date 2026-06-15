<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Validation\Support;

use Glueful\Tests\Support\Fixtures\Validation\ReservedNameRule;
use Glueful\Validation\Support\RuleParser;
use Glueful\Validation\Support\RuleRegistry;
use Glueful\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class RuleParserCustomRuleTest extends TestCase
{
    public function testRuleParserUsesRegisteredCustomRule(): void
    {
        $registry = new RuleRegistry(RuleParser::builtinRuleNames());
        $registry->register('reserved_name', ReservedNameRule::class);
        $parser = new RuleParser(null, $registry);
        $compiled = $parser->parse(['username' => 'required|string|reserved_name']);
        $validator = new Validator($compiled);
        self::assertNotSame([], $validator->validate(['username' => 'admin']));
        self::assertSame([], $validator->validate(['username' => 'alice']));
    }
}
