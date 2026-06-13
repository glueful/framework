<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Middleware\AuthMiddleware;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;

final class AuthMiddlewareTokenFallbackTest extends TestCase
{
    public function testQueryTokenFallbackIsDisabledByDefault(): void
    {
        $middleware = new AuthMiddleware(context: $this->context(false));

        $this->assertNull($this->extractFallback($middleware, Request::create('/private?token=secret-token')));
    }

    public function testQueryTokenFallbackCanBeExplicitlyEnabled(): void
    {
        $middleware = new AuthMiddleware(context: $this->context(true));

        $this->assertSame('secret-token', $this->extractFallback(
            $middleware,
            Request::create('/private?token=secret-token')
        ));
    }

    private function context(bool $allowQueryParam): ApplicationContext
    {
        $context = ApplicationContext::forTesting(dirname(__DIR__, 3));
        $context->mergeConfigDefaults('security', [
            'tokens' => [
                'allow_query_param' => $allowQueryParam,
            ],
        ]);
        return $context;
    }

    private function extractFallback(AuthMiddleware $middleware, Request $request): ?string
    {
        $method = new ReflectionMethod($middleware, 'extractTokenFallback');
        $method->setAccessible(true);
        $token = $method->invoke($middleware, $request);

        return is_string($token) ? $token : null;
    }
}
