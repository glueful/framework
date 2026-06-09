<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container;

use Glueful\Container\Bootstrap\ContainerFactory;
use PHPUnit\Framework\TestCase;

final class ContainerPrecedenceTest extends TestCase
{
    public function test_extension_defs_override_core_defaults(): void
    {
        $core = ['seam' => 'core-default', 'core_only' => 'X'];
        $ext  = ['seam' => 'extension-override', 'ext_only' => 'Y'];

        $merged = ContainerFactory::mergeExtensionDefs($core, $ext);

        self::assertSame('extension-override', $merged['seam']);   // the fix: extension WINS
        self::assertSame('X', $merged['core_only']);               // core-only preserved
        self::assertSame('Y', $merged['ext_only']);                // extension-only added
    }
}
