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
        self::assertSame('http', $config['security_schemes']['BearerAuth']['type']);
        self::assertSame('bearer', $config['security_schemes']['BearerAuth']['scheme']);
        self::assertSame('header', $config['security_schemes']['ApiKeyAuth']['in']);
    }

    public function testMiddlewareMapIsDeclared(): void
    {
        $config = require dirname(__DIR__, 4) . '/config/documentation.php';

        self::assertIsArray($config['middleware_map']);
        self::assertSame(['BearerAuth'], $config['middleware_map']['auth']);
        self::assertSame(['ApiKeyAuth'], $config['middleware_map']['api_key']);
    }

    public function testGeneratorSwitchKeyIsRemoved(): void
    {
        // The comments generator was removed; reflect is the only generator, so
        // there is no longer a 'generator' switch key in the config.
        $config = require dirname(__DIR__, 4) . '/config/documentation.php';

        self::assertArrayNotHasKey('generator', $config);
    }
}
