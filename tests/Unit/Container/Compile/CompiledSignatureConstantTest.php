<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container\Compile;

use Glueful\Container\Compile\ContainerCompiler;
use Glueful\Container\Definition\ValueDefinition;
use PHPUnit\Framework\TestCase;

/** A compiled container states which definitions it was built from, so a loader can refuse a stale one. */
final class CompiledSignatureConstantTest extends TestCase
{
    public function testTheSignatureIsEmittedAsAConstant(): void
    {
        $code = (new ContainerCompiler())->compile(
            ['v' => new ValueDefinition('v', 1)],
            'Signed',
            'Glueful\\Tests\\Compiled',
            'abc123',
        );

        self::assertStringContainsString("public const DEFINITIONS_SIGNATURE = 'abc123';", $code);
    }
}
