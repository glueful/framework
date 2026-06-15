<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Validation\Support;

use Glueful\Tests\Support\Fixtures\Validation\ReservedNameRule;
use Glueful\Validation\Support\RuleRegistry;
use PHPUnit\Framework\TestCase;

final class RuleRegistryTest extends TestCase
{
    public function testRegistersAndResolvesACustomRule(): void
    {
        $r = new RuleRegistry();
        $r->register('reserved_name', ReservedNameRule::class);
        self::assertTrue($r->has('reserved_name'));
        self::assertSame(ReservedNameRule::class, $r->classFor('reserved_name'));
    }

    public function testDuplicateRegistrationThrowsByDefault(): void
    {
        $r = new RuleRegistry();
        $r->register('reserved_name', ReservedNameRule::class);
        $this->expectException(\InvalidArgumentException::class);
        $r->register('reserved_name', ReservedNameRule::class);
    }

    public function testOverwriteAllowsReRegistration(): void
    {
        $r = new RuleRegistry();
        $r->register('reserved_name', ReservedNameRule::class);
        $r->register('reserved_name', ReservedNameRule::class, overwrite: true);
        self::assertSame(ReservedNameRule::class, $r->classFor('reserved_name'));
    }

    public function testRegisteringBuiltinNameThrows(): void
    {
        $r = new RuleRegistry(['required', 'string', 'array']);
        $this->expectException(\InvalidArgumentException::class);
        $r->register('required', ReservedNameRule::class);
    }

    public function testBuiltinNameIsReservedEvenWithOverwrite(): void
    {
        $r = new RuleRegistry(['required']);
        $this->expectException(\InvalidArgumentException::class);
        $r->register('required', ReservedNameRule::class, overwrite: true); // built-ins never overridable
    }

    public function testRejectsNonRuleClass(): void
    {
        $r = new RuleRegistry();
        $this->expectException(\InvalidArgumentException::class);
        $r->register('bad', \stdClass::class);
    }
}
