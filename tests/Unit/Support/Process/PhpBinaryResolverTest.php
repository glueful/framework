<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Process;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Support\Process\PhpBinaryResolver;
use PHPUnit\Framework\TestCase;

final class PhpBinaryResolverTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            @rmdir($dir);
        }
    }

    public function test_uses_configured_override(): void
    {
        $context = $this->context();
        $context->mergeConfigDefaults('extensions', [
            'install' => ['php_binary' => '/opt/php-cli/bin/php'],
        ]);

        $this->assertSame('/opt/php-cli/bin/php', (new PhpBinaryResolver($context))->resolve());
    }

    public function test_auto_detects_a_php_when_no_override(): void
    {
        $resolved = (new PhpBinaryResolver($this->context()))->resolve();

        $this->assertNotSame('', $resolved);
        $this->assertStringContainsStringIgnoringCase('php', $resolved);
    }

    private function context(): ApplicationContext
    {
        $base = sys_get_temp_dir() . '/php_' . bin2hex(random_bytes(6));
        mkdir($base, 0755, true);
        $this->tempDirs[] = $base;
        return ApplicationContext::forTesting($base);
    }
}
