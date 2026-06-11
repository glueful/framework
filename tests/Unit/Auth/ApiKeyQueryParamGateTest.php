<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth;

use Glueful\Auth\ApiKeyAuthenticationProvider;
use Glueful\Bootstrap\ApplicationContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The `?api_key=` query parameter leaks into access logs, proxies, browser history, and
 * Referer headers, so it must be off by default and only honored when explicitly enabled
 * via `security.api_keys.allow_query_param` (mirroring the JWT `allow_query_param` gate).
 * The `X-API-Key` header always works.
 */
final class ApiKeyQueryParamGateTest extends TestCase
{
    private function extract(ApiKeyAuthenticationProvider $provider, Request $request): ?string
    {
        $method = new \ReflectionMethod($provider, 'extractApiKeyFromRequest');
        $method->setAccessible(true);
        /** @var string|null $result */
        $result = $method->invoke($provider, $request);
        return $result;
    }

    public function test_query_param_ignored_by_default(): void
    {
        $provider = new ApiKeyAuthenticationProvider();
        $request = Request::create('/x?api_key=secret-from-url');

        self::assertNull($this->extract($provider, $request), 'api_key query param must be ignored by default');
    }

    public function test_header_is_always_honored(): void
    {
        $provider = new ApiKeyAuthenticationProvider();
        $request = Request::create('/x');
        $request->headers->set('X-API-Key', 'header-key');

        self::assertSame('header-key', $this->extract($provider, $request));
    }

    public function test_query_param_used_when_explicitly_enabled(): void
    {
        $context = new ApplicationContext(__DIR__);
        $context->mergeConfigDefaults('security', ['api_keys' => ['allow_query_param' => true]]);
        $provider = new ApiKeyAuthenticationProvider($context);
        $request = Request::create('/x?api_key=url-key');

        self::assertSame('url-key', $this->extract($provider, $request));
    }
}
