<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing\Middleware;

use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Http\Exceptions\Domain\SecurityException;
use Glueful\Routing\Middleware\CSRFMiddleware;
use Glueful\Routing\Middleware\MiddlewareParams;
use Glueful\Routing\Middleware\RequestResponseLoggingMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The router splits "name:a,b,c" into STRING params. Middleware that only accepted real
 * ints/bools silently ignored every value written in a route definition.
 */
final class MiddlewareStringParamsTest extends TestCase
{
    /** @return iterable<string, array{mixed, ?bool}> */
    public static function boolCases(): iterable
    {
        yield 'real true' => [true, true];
        yield 'real false' => [false, false];
        yield 'string true' => ['true', true];
        yield 'string false' => ['false', false];
        yield 'string 1' => ['1', true];
        yield 'string 0' => ['0', false];
        yield 'mixed case' => ['FALSE', false];
        yield 'garbage' => ['maybe', null];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
    }

    #[DataProvider('boolCases')]
    public function testBoolCoercion(mixed $value, ?bool $expected): void
    {
        self::assertSame($expected, MiddlewareParams::bool($value));
    }

    /** @return iterable<string, array{mixed, ?int}> */
    public static function intCases(): iterable
    {
        yield 'real int' => [300, 300];
        yield 'numeric string' => ['300', 300];
        yield 'padded' => [' 42 ', 42];
        yield 'float string' => ['1.5', null];
        yield 'garbage' => ['abc', null];
        yield 'bool' => [true, null];
        yield 'null' => [null, null];
    }

    #[DataProvider('intCases')]
    public function testIntCoercion(mixed $value, ?int $expected): void
    {
        self::assertSame($expected, MiddlewareParams::int($value));
    }

    public function testCsrfHonoursStringParamsFromRouteDefinitions(): void
    {
        $csrf = new CSRFMiddleware(cache: new ArrayCacheDriver());

        $issuing = Request::create('/account', 'GET');
        $issuing->server->set('REMOTE_ADDR', '203.0.113.10');
        $token = $csrf->generateToken($issuing);

        // A cross-origin POST with a valid token and no double-submit cookie: only passes when
        // the route's "csrf:3600,false,false" really turns double-submit and origin checks off.
        $request = Request::create('/account/profile', 'POST');
        $request->server->set('REMOTE_ADDR', '203.0.113.10');
        $request->headers->set('X-CSRF-Token', $token);
        $request->headers->set('Origin', 'https://elsewhere.example');

        try {
            $response = $csrf->handle($request, static fn (): Response => new Response('next'), '3600', 'false', 'false');
        } catch (SecurityException $e) {
            self::fail('String params were ignored: ' . $e->getMessage());
        }

        self::assertSame('next', $response->getContent());
    }

    public function testRequestLoggingHonoursStringParamsFromRouteDefinitions(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array<string, mixed>> */
            public array $contexts = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };
        $middleware = new RequestResponseLoggingMiddleware(
            logMode: 'request',
            logHeaders: false,
            logBodies: false,
            logger: $logger
        );

        $request = Request::create('/things', 'POST', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'hello');

        // "request_logging:request,true,true"
        $middleware->handle($request, static fn (): Response => new Response('ok'), 'request', 'true', 'true');

        self::assertCount(1, $logger->contexts);
        self::assertArrayHasKey('headers', $logger->contexts[0]);
        self::assertArrayHasKey('body', $logger->contexts[0]);
    }
}
