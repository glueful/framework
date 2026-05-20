<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Support\Documentation\DocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;

final class DocGeneratorSecurityTest extends TestCase
{
    public function testEmitsAllConfiguredSecuritySchemes(): void
    {
        $registry = new SecuritySchemeRegistry(
            schemes: [
                'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                'ApiKeyAuth' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
            ],
            middlewareMap: ['auth' => ['BearerAuth'], 'api_key' => ['ApiKeyAuth']],
        );

        $generator = new DocGenerator(openApiVersion: '3.1.0');
        $generator->setSecurityRegistry($registry);

        $spec = json_decode($generator->getSwaggerJson(), true);
        self::assertIsArray($spec);
        self::assertArrayHasKey('BearerAuth', $spec['components']['securitySchemes']);
        self::assertArrayHasKey('ApiKeyAuth', $spec['components']['securitySchemes']);
    }

    public function testFallsBackToDefaultBearerAuthWhenNoRegistrySet(): void
    {
        $generator = new DocGenerator(openApiVersion: '3.1.0');
        $spec = json_decode($generator->getSwaggerJson(), true);

        self::assertIsArray($spec);
        self::assertArrayHasKey('BearerAuth', $spec['components']['securitySchemes']);
        self::assertSame('http', $spec['components']['securitySchemes']['BearerAuth']['type']);
    }
}
