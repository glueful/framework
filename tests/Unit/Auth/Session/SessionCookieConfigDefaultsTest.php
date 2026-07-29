<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth\Session;

use Glueful\Auth\Session\SessionCookieConfig;
use Glueful\Bootstrap\ApplicationContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;

final class SessionCookieConfigDefaultsTest extends TestCase
{
    private function config(): SessionCookieConfig
    {
        return SessionCookieConfig::fromContext(ApplicationContext::forTesting(dirname(__DIR__, 4)));
    }

    public function testTheTransportIsOffByDefault(): void
    {
        // A framework upgrade must not switch on a new authentication transport by itself.
        self::assertFalse($this->config()->enabled);
    }

    public function testSecureDefaultsAreNotOptIn(): void
    {
        $config = $this->config();

        self::assertTrue($config->secure);
        self::assertSame(Cookie::SAMESITE_LAX, $config->sameSite);
        self::assertNull($config->domain, 'host-only by default');
        self::assertSame('/auth/session', $config->refreshPath, 'refresh must stay path-scoped');
    }

    public function testEveryDocumentedEnvVarAppearsInTheEnvExample(): void
    {
        $example = (string) file_get_contents(dirname(__DIR__, 4) . '/.env.example');

        foreach ([
            'SESSION_COOKIE_ENABLED',
            'SESSION_COOKIE_ACCESS_NAME',
            'SESSION_COOKIE_REFRESH_NAME',
            'SESSION_COOKIE_REFRESH_TTL',
            'SESSION_COOKIE_PATH',
            'SESSION_COOKIE_REFRESH_PATH',
            'SESSION_COOKIE_DOMAIN',
            'SESSION_COOKIE_SECURE',
            'SESSION_COOKIE_SAMESITE',
        ] as $key) {
            self::assertStringContainsString($key, $example, $key . ' is missing from .env.example');
        }
    }
}
