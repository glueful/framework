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
        self::assertSame('/api/v1/auth/session', $config->refreshPath, 'refresh must stay path-scoped');
    }

    public function testDefaultRefreshPathFollowsTheApiPrefixTheSessionRoutesLiveUnder(): void
    {
        // The session routes are registered under api_prefix() (routes/auth.php is an api_routes
        // file), so a bare `/auth/session` cookie path is never sent to `/api/v1/auth/session/*`.
        $context = ApplicationContext::forTesting(dirname(__DIR__, 4));
        $context->overrideConfig('api.versioning.prefix', '/svc');
        $context->overrideConfig('api.versioning.default', '2');

        self::assertSame('/svc/v2/auth/session', SessionCookieConfig::fromContext($context)->refreshPath);
    }

    public function testDefaultRefreshPathWithoutAnyApiPrefix(): void
    {
        $context = ApplicationContext::forTesting(dirname(__DIR__, 4));
        $context->overrideConfig('api.versioning.apply_prefix_to_routes', false);
        $context->overrideConfig('api.versioning.version_in_path', false);

        self::assertSame('/auth/session', SessionCookieConfig::fromContext($context)->refreshPath);
    }

    public function testAnExplicitlyConfiguredRefreshPathWins(): void
    {
        $context = ApplicationContext::forTesting(dirname(__DIR__, 4));
        $context->overrideConfig('auth.session_cookie.refresh_path', '/custom/session');

        self::assertSame('/custom/session', SessionCookieConfig::fromContext($context)->refreshPath);
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
