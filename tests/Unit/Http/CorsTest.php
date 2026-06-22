<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Http;

use Glueful\Http\Cors;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Regression: the standalone CORS handler's no-config default was permissive and its
 * credentials handling was broken.
 *
 * - With no origins configured the constructor forced ['*'], so an unconfigured
 *   production deployment allowed every cross-origin caller. It must fail closed.
 * - It read $corsConfig['supports_credentials'], but config/cors.php defines
 *   allow_credentials -- the configured value was ignored and the fallback
 *   (security.cors.supports_credentials = true) enabled credentials the config
 *   intended to disable. Both keys must be honored, defaulting to disabled.
 * - Credentials must never combine with a wildcard origin (the handler reflects the
 *   request Origin, so wildcard + credentials = any site can make credentialed calls).
 * - Reflecting the Origin requires Vary: Origin so caches don't serve one origin's
 *   CORS response to another.
 */
final class CorsTest extends TestCase
{
    public function test_no_configured_origins_fails_closed(): void
    {
        $cors = new Cors();

        self::assertSame(
            [],
            $this->configValue($cors, 'allowedOrigins'),
            'unconfigured origins must resolve to [] (deny all), not the [\'*\'] wildcard'
        );
        self::assertFalse(
            $this->invoke($cors, 'isOriginAllowed', ['https://evil.example']),
            'no configured origins must deny every cross-origin request'
        );
    }

    public function test_allow_credentials_key_from_cors_config_is_honored(): void
    {
        $cors = new Cors();

        $resolved = $this->invoke($cors, 'resolveConfig', [[
            'allowed_origins' => ['https://app.example'],
            'allow_credentials' => false,
        ]]);
        self::assertFalse(
            $resolved['supportsCredentials'],
            'config/cors.php uses allow_credentials; its value must not be silently ignored'
        );

        $resolved = $this->invoke($cors, 'resolveConfig', [[
            'allowed_origins' => ['https://app.example'],
            'allow_credentials' => true,
        ]]);
        self::assertTrue($resolved['supportsCredentials']);
    }

    public function test_supports_credentials_key_is_still_honored(): void
    {
        $cors = new Cors();

        $resolved = $this->invoke($cors, 'resolveConfig', [[
            'allowed_origins' => ['https://app.example'],
            'supports_credentials' => true,
        ]]);
        self::assertTrue(
            $resolved['supportsCredentials'],
            'security.cors uses supports_credentials; the legacy key must keep working'
        );
    }

    public function test_credentials_default_to_disabled_when_unconfigured(): void
    {
        $cors = new Cors();

        $resolved = $this->invoke($cors, 'resolveConfig', [[
            'allowed_origins' => ['https://app.example'],
        ]]);
        self::assertFalse(
            $resolved['supportsCredentials'],
            'credentials are opt-in; the unconfigured default must be disabled'
        );
    }

    public function test_credentials_never_combined_with_wildcard_origin(): void
    {
        $cors = new Cors(['allowedOrigins' => ['*'], 'supportsCredentials' => true]);

        $headers = $this->invoke($cors, 'responseCorsHeaders', ['https://any.example']);
        self::assertArrayNotHasKey(
            'Access-Control-Allow-Credentials',
            $headers,
            'wildcard origins reflect the caller; emitting Allow-Credentials would let any site send cookies'
        );

        $preflight = $this->invoke($cors, 'preflightCorsHeaders', ['https://any.example']);
        self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $preflight);
    }

    public function test_credentials_emitted_for_explicit_origin_allowlist(): void
    {
        $cors = new Cors(['allowedOrigins' => ['https://app.example'], 'supportsCredentials' => true]);

        $headers = $this->invoke($cors, 'responseCorsHeaders', ['https://app.example']);
        self::assertSame('true', $headers['Access-Control-Allow-Credentials'] ?? null);

        $preflight = $this->invoke($cors, 'preflightCorsHeaders', ['https://app.example']);
        self::assertSame('true', $preflight['Access-Control-Allow-Credentials'] ?? null);
    }

    public function test_vary_origin_is_set_when_reflecting_the_origin(): void
    {
        $cors = new Cors(['allowedOrigins' => ['https://app.example']]);

        $headers = $this->invoke($cors, 'responseCorsHeaders', ['https://app.example']);
        self::assertSame('Origin', $headers['Vary'] ?? null);
        self::assertSame('https://app.example', $headers['Access-Control-Allow-Origin'] ?? null);

        $preflight = $this->invoke($cors, 'preflightCorsHeaders', ['https://app.example']);
        self::assertSame('Origin', $preflight['Vary'] ?? null);
    }

    public function test_applyToResponse_sets_cors_headers_on_error_response_for_allowed_origin(): void
    {
        $cors = new Cors(['allowedOrigins' => ['https://app.example']]);
        $request = Request::create('/admin/setup', 'POST');
        $request->headers->set('Origin', 'https://app.example');
        // An error response — exactly the case the router's preflight handling doesn't cover.
        $response = new Response('{"errors":{}}', 422);

        $cors->applyToResponse($request, $response);

        self::assertSame(
            'https://app.example',
            $response->headers->get('Access-Control-Allow-Origin'),
            'cross-origin error responses must carry Access-Control-Allow-Origin so the browser exposes the body'
        );
        self::assertSame('Origin', $response->headers->get('Vary'));
    }

    public function test_applyToResponse_is_noop_for_disallowed_origin(): void
    {
        $cors = new Cors(['allowedOrigins' => ['https://app.example']]);
        $request = Request::create('/x', 'POST');
        $request->headers->set('Origin', 'https://evil.example');
        $response = new Response('', 422);

        $cors->applyToResponse($request, $response);

        self::assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_applyToResponse_is_noop_without_an_origin(): void
    {
        $cors = new Cors(['allowedOrigins' => ['https://app.example']]);
        $response = new Response('', 200);

        $cors->applyToResponse(Request::create('/x', 'GET'), $response);

        self::assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_applyToResponse_does_not_overwrite_an_existing_header(): void
    {
        $cors = new Cors(['allowedOrigins' => ['https://app.example']]);
        $request = Request::create('/x', 'OPTIONS');
        $request->headers->set('Origin', 'https://app.example');
        $response = new Response('', 204);
        $response->headers->set('Access-Control-Allow-Origin', 'https://preflight.example');

        $cors->applyToResponse($request, $response);

        self::assertSame(
            'https://preflight.example',
            $response->headers->get('Access-Control-Allow-Origin'),
            'an already-set header (e.g. from the preflight responder) must not be clobbered'
        );
    }

    private function configValue(Cors $cors, string $key): mixed
    {
        $ref = new \ReflectionProperty($cors, 'config');
        $ref->setAccessible(true);
        /** @var array<string, mixed> $config */
        $config = $ref->getValue($cors);

        return $config[$key];
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invoke(Cors $cors, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($cors, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($cors, $args);
    }
}
