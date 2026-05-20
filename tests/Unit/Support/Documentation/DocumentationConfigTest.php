<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use PHPUnit\Framework\TestCase;

final class DocumentationConfigTest extends TestCase
{
    public function testSecuritySchemesAreDeclared(): void
    {
        $config = require dirname(__DIR__, 4) . '/config/documentation.php';

        self::assertIsArray($config['security_schemes']);
        self::assertArrayHasKey('BearerAuth', $config['security_schemes']);
        self::assertArrayHasKey('ApiKeyAuth', $config['security_schemes']);
        self::assertSame('apiKey', $config['security_schemes']['ApiKeyAuth']['type']);
        self::assertSame('X-API-Key', $config['security_schemes']['ApiKeyAuth']['name']);
    }

    public function testMiddlewareMapIsDeclared(): void
    {
        $config = require dirname(__DIR__, 4) . '/config/documentation.php';

        self::assertIsArray($config['middleware_map']);
        self::assertSame(['BearerAuth'], $config['middleware_map']['auth']);
        self::assertSame(['ApiKeyAuth'], $config['middleware_map']['api_key']);
    }
}
