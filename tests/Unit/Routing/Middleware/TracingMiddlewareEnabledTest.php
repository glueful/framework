<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing\Middleware;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Bootstrap\ContainerFactory;
use Glueful\Http\Response;
use Glueful\Observability\Tracing\NoopSpanBuilder;
use Glueful\Observability\Tracing\SpanBuilderInterface;
use Glueful\Observability\Tracing\TracerInterface;
use Glueful\Routing\Middleware\TracingMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * `observability.tracing.enabled` (TRACING_ENABLED) must gate the `tracing` middleware:
 * when disabled it passes the request straight through without opening a span.
 */
final class TracingMiddlewareEnabledTest extends TestCase
{
    private function countingTracer(int &$spans): TracerInterface
    {
        return new class ($spans) implements TracerInterface {
            public function __construct(private int &$spans)
            {
            }

            /** @param array<string, mixed> $attrs */
            public function startSpan(string $name, array $attrs = []): SpanBuilderInterface
            {
                $this->spans++;
                return new NoopSpanBuilder();
            }
        };
    }

    public function testDisabledMiddlewareOpensNoSpan(): void
    {
        $spans = 0;
        $middleware = new TracingMiddleware($this->countingTracer($spans), false);

        $response = $middleware->handle(Request::create('/x'), static fn() => new Response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $spans);
    }

    public function testEnabledMiddlewareOpensASpan(): void
    {
        $spans = 0;
        $middleware = new TracingMiddleware($this->countingTracer($spans), true);

        $middleware->handle(Request::create('/x'), static fn() => new Response('ok', 200));

        $this->assertSame(1, $spans);
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function configProvider(): array
    {
        return ['disabled' => [false, false], 'enabled' => [true, true]];
    }

    /**
     * @dataProvider configProvider
     */
    public function testContainerHonoursTracingEnabledConfig(mixed $configured, bool $expected): void
    {
        $context = ApplicationContext::forTesting(dirname(__DIR__, 4));
        $context->overrideConfig('observability.tracing.enabled', $configured);
        $container = ContainerFactory::create($context, false);

        $middleware = $container->get('tracing');
        $this->assertInstanceOf(TracingMiddleware::class, $middleware);
        $this->assertSame($expected, $middleware->isEnabled());
    }
}
