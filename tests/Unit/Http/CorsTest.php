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

    /**
     * Regression: origin patterns and development_allow_all were honoured only by the router's
     * preflight responder. Actual responses go through applyToResponse(), which matched the exact
     * list / '*' only — so a pattern-allowed origin passed preflight and then had its real
     * response blocked by the browser.
     */
    public function test_applyToResponse_honours_configured_origin_patterns(): void
    {
        $cors = $this->fromCorsConfig([
            'allowed_origins' => ['https://app.example'],
            'allowed_origin_patterns' => ['/^https:\/\/[a-z0-9-]+\.tenant\.example$/'],
        ]);
        $request = Request::create('/x', 'GET');
        $request->headers->set('Origin', 'https://acme.tenant.example');
        $response = new Response('', 200);

        $cors->applyToResponse($request, $response);

        self::assertSame(
            'https://acme.tenant.example',
            $response->headers->get('Access-Control-Allow-Origin'),
            'a pattern-allowed origin must get Access-Control-Allow-Origin on the actual response'
        );
        self::assertSame('Origin', $response->headers->get('Vary'));
    }

    public function test_applyToResponse_rejects_origin_not_matching_any_pattern(): void
    {
        $cors = $this->fromCorsConfig([
            'allowed_origins' => [],
            'allowed_origin_patterns' => ['/^https:\/\/[a-z0-9-]+\.tenant\.example$/'],
        ]);
        $request = Request::create('/x', 'GET');
        $request->headers->set('Origin', 'https://tenant.example.evil.test');
        $response = new Response('', 200);

        $cors->applyToResponse($request, $response);

        self::assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_applyToResponse_honours_development_allow_all(): void
    {
        $cors = $this->fromCorsConfig([
            'allowed_origins' => [],
            'development_allow_all' => true,
        ]);
        $request = Request::create('/x', 'GET');
        $request->headers->set('Origin', 'http://localhost:5173');
        $response = new Response('', 200);

        $cors->applyToResponse($request, $response);

        self::assertSame('http://localhost:5173', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->headers->get('Vary'));
    }

    public function test_pattern_matched_origin_with_credentials_echoes_specific_origin(): void
    {
        $cors = $this->fromCorsConfig([
            'allowed_origins' => [],
            'allowed_origin_patterns' => ['/^https:\/\/[a-z0-9-]+\.tenant\.example$/'],
            'allow_credentials' => true,
        ]);
        $request = Request::create('/x', 'GET');
        $request->headers->set('Origin', 'https://acme.tenant.example');
        $response = new Response('', 200);

        $cors->applyToResponse($request, $response);

        self::assertSame('https://acme.tenant.example', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        self::assertSame('Origin', $response->headers->get('Vary'));
    }

    public function test_shared_matcher_applies_one_rule_for_every_source(): void
    {
        $patterns = ['/^https:\/\/[a-z0-9-]+\.tenant\.example$/'];

        self::assertTrue(Cors::originMatches('https://app.example', ['https://app.example'], [], false));
        self::assertTrue(Cors::originMatches('https://any.example', ['*'], [], false));
        self::assertTrue(Cors::originMatches('https://acme.tenant.example', [], $patterns, false));
        self::assertTrue(Cors::originMatches('https://any.example', [], [], true));
        self::assertFalse(Cors::originMatches('https://evil.example', ['https://app.example'], $patterns, false));
        self::assertFalse(Cors::originMatches('', ['*'], $patterns, true), 'a missing Origin never matches');
    }

    /**
     * Build a handler from a raw config/cors.php-shaped array (as the constructor does from config()).
     *
     * @param array<string, mixed> $corsConfig
     */
    private function fromCorsConfig(array $corsConfig): Cors
    {
        /** @var array<string, mixed> $resolved */
        $resolved = $this->invoke(new Cors(), 'resolveConfig', [$corsConfig]);

        return new Cors($resolved);
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
