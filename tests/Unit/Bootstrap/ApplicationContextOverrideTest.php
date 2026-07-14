<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Bootstrap;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use PHPUnit\Framework\TestCase;

final class ApplicationContextOverrideTest extends TestCase
{
    /** @param array<string,mixed> $file */
    private function context(array $file): ApplicationContext
    {
        $loader = new class ($file) extends ConfigurationLoader {
            /** @param array<string,mixed> $file */
            public function __construct(private readonly array $file)
            {
            }

            public function loadConfig(string $name): array
            {
                return $this->file[$name] ?? [];
            }
        };
        $ctx = new ApplicationContext('/tmp/glueful-config-test', 'testing');
        $ctx->setConfigLoader($loader);

        return $ctx;
    }

    public function testOverrideWinsOverFileConfig(): void
    {
        $ctx = $this->context(['tenancy' => ['public_origin' => ['base_domain' => 'file.example']]]);
        self::assertSame('file.example', $ctx->getConfig('tenancy.public_origin.base_domain'));

        $ctx->overrideConfig('tenancy.public_origin.base_domain', 'override.example');
        self::assertSame('override.example', $ctx->getConfig('tenancy.public_origin.base_domain'));
    }

    public function testOverrideInvalidatesParentAndChildCachedReads(): void
    {
        $ctx = $this->context(['tenancy' => ['public_origin' => ['base_domain' => 'file.example']]]);
        // Prime both the parent-key and child-key caches.
        self::assertSame(['base_domain' => 'file.example'], $ctx->getConfig('tenancy.public_origin'));
        self::assertSame('file.example', $ctx->getConfig('tenancy.public_origin.base_domain'));

        $ctx->overrideConfig('tenancy.public_origin.base_domain', 'override.example');

        self::assertSame('override.example', $ctx->getConfig('tenancy.public_origin.base_domain'));
        self::assertSame(['base_domain' => 'override.example'], $ctx->getConfig('tenancy.public_origin'));
    }

    public function testOverrideSurvivesClearConfigCache(): void
    {
        $ctx = $this->context(['tenancy' => ['public_origin' => ['base_domain' => 'file.example']]]);
        $ctx->overrideConfig('tenancy.public_origin.base_domain', 'override.example');
        $ctx->clearConfigCache();
        self::assertSame('override.example', $ctx->getConfig('tenancy.public_origin.base_domain'));
    }

    public function testOverrideRejectedAfterBoot(): void
    {
        $ctx = $this->context([]);
        $ctx->markBooted();
        $this->expectException(\LogicException::class);
        $ctx->overrideConfig('tenancy.public_origin.base_domain', 'x.example');
    }

    public function testOverrideRejectsAnEmptyDotPath(): void
    {
        $ctx = $this->context([]);
        $this->expectException(\InvalidArgumentException::class);
        $ctx->overrideConfig('tenancy..base_domain', 'x.example');
    }
}
