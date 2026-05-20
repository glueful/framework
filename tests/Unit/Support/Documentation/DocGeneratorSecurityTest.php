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

    public function testSecurityForDelegatesToRegistryWhenSet(): void
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

        $method = new \ReflectionMethod($generator, 'securityFor');
        $method->setAccessible(true);

        self::assertSame(
            [['BearerAuth' => []]],
            $method->invoke($generator, ['auth']),
        );
        self::assertSame(
            [['BearerAuth' => []], ['ApiKeyAuth' => []]],
            $method->invoke($generator, ['auth', 'api_key']),
        );
        self::assertSame([], $method->invoke($generator, ['rate_limit']));
    }

    public function testSecurityForFallsBackToLegacyBehaviorWithoutRegistry(): void
    {
        $generator = new DocGenerator(openApiVersion: '3.1.0');

        $method = new \ReflectionMethod($generator, 'securityFor');
        $method->setAccessible(true);

        self::assertSame([['BearerAuth' => []]], $method->invoke($generator, ['auth']));
        self::assertSame([], $method->invoke($generator, ['rate_limit']));
    }

    public function testDeclaresErrorResponseSchemaComponent(): void
    {
        $generator = new DocGenerator(openApiVersion: '3.1.0');
        $spec = json_decode($generator->getSwaggerJson(), true);

        self::assertIsArray($spec);
        self::assertArrayHasKey('ErrorResponse', $spec['components']['schemas']);

        $schema = $spec['components']['schemas']['ErrorResponse'];
        self::assertSame('object', $schema['type']);
        self::assertSame(['success', 'message', 'error'], $schema['required']);

        $errorProps = $schema['properties']['error']['properties'];
        self::assertSame('integer', $errorProps['code']['type']);
        self::assertSame('string', $errorProps['error_code']['type']);
        self::assertContains('NOT_FOUND', $errorProps['error_code']['enum']);
        self::assertContains('FORBIDDEN', $errorProps['error_code']['enum']);
        self::assertSame('date-time', $errorProps['timestamp']['format']);
    }
}
