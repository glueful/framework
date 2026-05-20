<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;

final class SecuritySchemeRegistryTest extends TestCase
{
    public function testReturnsConfiguredSchemes(): void
    {
        $registry = new SecuritySchemeRegistry([
            'BearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT',
            ],
            'ApiKeyAuth' => [
                'type' => 'apiKey',
                'in' => 'header',
                'name' => 'X-API-Key',
            ],
        ]);

        $schemes = $registry->getSchemes();
        self::assertArrayHasKey('BearerAuth', $schemes);
        self::assertArrayHasKey('ApiKeyAuth', $schemes);
        self::assertSame('apiKey', $schemes['ApiKeyAuth']['type']);
    }

    public function testFallsBackToBearerAuthWhenEmpty(): void
    {
        $registry = new SecuritySchemeRegistry([]);
        $schemes = $registry->getSchemes();
        self::assertArrayHasKey('BearerAuth', $schemes);
        self::assertSame('http', $schemes['BearerAuth']['type']);
    }
}
