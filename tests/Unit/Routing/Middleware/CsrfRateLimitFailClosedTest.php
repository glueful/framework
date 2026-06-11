<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing\Middleware;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Middleware\CSRFMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The CSRF token-generation rate limiter fails OPEN (allows) when it has no cache, to
 * preserve availability. A stricter posture can opt into fail-CLOSED (deny) via
 * security.csrf.rate_limit_fail_closed.
 */
final class CsrfRateLimitFailClosedTest extends TestCase
{
    private function checkRateLimit(CSRFMiddleware $middleware, Request $request): bool
    {
        $method = new \ReflectionMethod($middleware, 'checkRateLimit');
        $method->setAccessible(true);
        /** @var bool $result */
        $result = $method->invoke($middleware, $request);
        return $result;
    }

    public function test_fails_open_by_default_without_cache(): void
    {
        $context = new ApplicationContext(__DIR__);
        $middleware = new CSRFMiddleware(context: $context);

        self::assertTrue(
            $this->checkRateLimit($middleware, Request::create('/x')),
            'with no cache and no opt-in, the limiter must fail open (allow)'
        );
    }

    public function test_fails_closed_when_configured_without_cache(): void
    {
        $context = new ApplicationContext(__DIR__);
        $context->mergeConfigDefaults('security', ['csrf' => ['rate_limit_fail_closed' => true]]);
        $middleware = new CSRFMiddleware(context: $context);

        self::assertFalse(
            $this->checkRateLimit($middleware, Request::create('/x')),
            'with fail-closed configured and no cache, the limiter must deny'
        );
    }
}
