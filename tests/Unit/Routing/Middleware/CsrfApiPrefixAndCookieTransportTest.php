<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing\Middleware;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Http\Exceptions\Domain\SecurityException;
use Glueful\Routing\Middleware\CSRFMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exempt routes are written relative to the API prefix (`auth/login`, `webhooks/*`), so they
 * must match `/api/v1/...`; and a cookie-authenticated write must never inherit the bearer
 * Origin/Referer relaxation just because session_cookie injected an Authorization header.
 */
final class CsrfApiPrefixAndCookieTransportTest extends TestCase
{
    /** @param array<string, mixed> $versioning */
    private function context(array $versioning = []): ApplicationContext
    {
        $context = new ApplicationContext(__DIR__);
        $context->mergeConfigDefaults('api', ['versioning' => $versioning + [
            'prefix' => '/api',
            'apply_prefix_to_routes' => true,
            'version_in_path' => true,
            'default' => '1',
        ]]);

        return $context;
    }

    /** @param array<string> $exempt */
    private function csrf(ApplicationContext $context, array $exempt): CSRFMiddleware
    {
        return new CSRFMiddleware(
            exemptRoutes: $exempt,
            allowedOrigins: ['https://app.example.test'],
            cache: new ArrayCacheDriver(),
            context: $context,
        );
    }

    private function passes(CSRFMiddleware $csrf, Request $request): bool
    {
        try {
            $response = $csrf->handle($request, static fn (): Response => new Response('next'));
        } catch (SecurityException) {
            return false;
        }

        return $response instanceof Response && $response->getContent() === 'next';
    }

    public function testShippedRelativeEntriesMatchUnderTheApiPrefix(): void
    {
        $csrf = $this->csrf($this->context(), ['auth/login', 'webhooks/*']);

        self::assertTrue($this->passes($csrf, Request::create('/api/v1/auth/login', 'POST')));
        self::assertTrue($this->passes($csrf, Request::create('/api/v1/webhooks/stripe', 'POST')));
    }

    public function testACustomPrefixIsStrippedToo(): void
    {
        $csrf = $this->csrf($this->context(['prefix' => '/rest', 'default' => '2']), ['webhooks/*']);

        self::assertTrue($this->passes($csrf, Request::create('/rest/v2/webhooks/stripe', 'POST')));
    }

    public function testFullPathEntriesKeepWorking(): void
    {
        $csrf = $this->csrf($this->context(), ['api/v1/webhooks/*']);

        self::assertTrue($this->passes($csrf, Request::create('/api/v1/webhooks/stripe', 'POST')));
    }

    public function testNonExemptPathsUnderThePrefixAreStillProtected(): void
    {
        $csrf = $this->csrf($this->context(), ['webhooks/*']);

        self::assertFalse($this->passes($csrf, Request::create('/api/v1/posts', 'POST')));
    }

    /**
     * The shipped `webhooks/*` exemption was meant for inbound receivers, but once entries matched
     * under the API prefix it also exempted the authenticated management API at
     * /api/v1/webhooks/{subscriptions,deliveries}. Inbound receivers now live under webhooks/inbound/.
     */
    public function testShippedExemptionsCoverInboundWebhooksButNotTheManagementApi(): void
    {
        /** @var array{csrf: array{exemptRoutes: array<string>}} $security */
        $security = require dirname(__DIR__, 4) . '/config/security.php';
        $csrf = $this->csrf($this->context(), $security['csrf']['exemptRoutes']);

        self::assertTrue($this->passes($csrf, Request::create('/webhooks/inbound/stripe', 'POST')));
        self::assertFalse($this->passes($csrf, Request::create('/api/v1/webhooks/subscriptions', 'POST')));
        self::assertFalse($this->passes($csrf, Request::create('/api/v1/webhooks/deliveries/7/retry', 'POST')));
    }

    public function testBearerWithoutOriginStillPassesTheOriginCheck(): void
    {
        // Regression guard: a real bearer request is only relaxed at the Origin/Referer step,
        // so it fails later at token validation (not CSRF_ORIGIN_MISMATCH).
        $csrf = $this->csrf($this->context(), []);
        $request = Request::create('/api/v1/posts', 'POST');
        $request->headers->set('Authorization', 'Bearer real-token');
        $request->attributes->set('auth_transport', 'bearer');

        try {
            $csrf->handle($request, static fn (): Response => new Response('next'));
            self::fail('expected token validation to fail');
        } catch (SecurityException $e) {
            self::assertSame(419, $e->getStatusCode());
        }
    }

    public function testCookieTransportNeverGetsTheBearerOriginRelaxation(): void
    {
        $csrf = $this->csrf($this->context(), []);
        $request = Request::create('/api/v1/posts', 'POST');
        // What session_cookie leaves behind for a cookie-only request.
        $request->headers->set('Authorization', 'Bearer cookie-access-token');
        $request->attributes->set('auth_transport', 'cookie');

        try {
            $csrf->handle($request, static fn (): Response => new Response('next'));
            self::fail('expected the origin check to reject the request');
        } catch (SecurityException $e) {
            self::assertSame(403, $e->getStatusCode());
        }
    }
}
