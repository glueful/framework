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

    public function testHasReturnsTrueForKnownSchemeAndFalseOtherwise(): void
    {
        $registry = new SecuritySchemeRegistry([
            'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
        ]);

        self::assertTrue($registry->has('BearerAuth'));
        self::assertFalse($registry->has('NoSuchScheme'));
    }

    public function testResolvesSchemesForMiddlewareList(): void
    {
        $registry = new SecuritySchemeRegistry(
            schemes: [
                'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                'ApiKeyAuth' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
            ],
            middlewareMap: [
                'auth' => ['BearerAuth'],
                'api_key' => ['ApiKeyAuth'],
            ],
        );

        self::assertSame(
            [['BearerAuth' => []]],
            $registry->securityFor(['auth', 'rate_limit']),
        );

        self::assertSame(
            [['BearerAuth' => []], ['ApiKeyAuth' => []]],
            $registry->securityFor(['auth', 'api_key']),
        );

        self::assertSame([], $registry->securityFor(['rate_limit']));
    }

    public function testSecurityForDeduplicatesRepeatedMiddleware(): void
    {
        $registry = new SecuritySchemeRegistry(
            schemes: ['BearerAuth' => ['type' => 'http', 'scheme' => 'bearer']],
            middlewareMap: ['auth' => ['BearerAuth']],
        );

        self::assertSame(
            [['BearerAuth' => []]],
            $registry->securityFor(['auth', 'auth']),
        );
    }
}
