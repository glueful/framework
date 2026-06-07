<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\Contracts\EdgeCacheInterface;
use Glueful\Cache\NullEdgeCache;
use Glueful\Controllers\Traits\ResponseCachingTrait;
use Glueful\Http\Response;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proves ResponseCachingTrait::edgeCacheResponse() runs on the
 * EdgeCacheInterface seam: it resolves the interface (not the concrete
 * EdgeCacheService) from the container and calls ONLY generateCacheHeaders().
 *
 * With the NullEdgeCache no-op bound, generateCacheHeaders() returns [], so no
 * edge cache-control header is emitted and the original Response is returned
 * untouched.
 */
final class ResponseCachingTraitEdgeTest extends TestCase
{
    public function testReturnsSameResponseInstanceWithNullEdgeCache(): void
    {
        $controller = $this->makeController(new NullEdgeCache());
        $response = Response::success(['ok' => true]);

        $returned = $controller->callEdgeCacheResponse($response, '/api/posts');

        self::assertSame($response, $returned);
    }

    public function testCallsOnlyGenerateCacheHeadersOnTheSeam(): void
    {
        $spy = new SpyEdgeCache();
        $controller = $this->makeController($spy);
        $response = Response::success(['ok' => true]);

        $returned = $controller->callEdgeCacheResponse($response, '/api/posts');

        self::assertSame($response, $returned);
        // The trait calls generateCacheHeaders($pattern, $contentType) exactly once.
        self::assertSame(
            [['/api/posts', 'application/json']],
            $spy->generateCacheHeadersCalls,
            'edgeCacheResponse() must call generateCacheHeaders($pattern, $contentType)'
        );
        // It must not touch any other seam method.
        self::assertSame([], $spy->otherCalls, 'edgeCacheResponse() must call ONLY generateCacheHeaders()');
    }

    public function testResolvesInterfaceNotConcreteService(): void
    {
        $spy = new SpyEdgeCache();
        $controller = $this->makeController($spy);

        $controller->callEdgeCacheResponse(Response::success([]), '/api/posts');

        // The container records what was requested; assert the seam interface was resolved.
        self::assertContains(EdgeCacheInterface::class, $controller->container()->resolved);
    }

    private function makeController(EdgeCacheInterface $edgeCache): EdgeCachingControllerStub
    {
        $container = new FakeContainer([EdgeCacheInterface::class => $edgeCache]);
        $context = new ApplicationContext(basePath: \sys_get_temp_dir(), environment: 'testing');
        $context->setContainer($container);

        $request = Request::create('/api/posts');
        $request->headers->set('Accept', 'application/json');

        return new EdgeCachingControllerStub($context, $request);
    }
}

/**
 * Minimal controller exercising the trait without the full BaseController stack.
 * Mirrors the properties the trait reads: getContext(), $request, $currentUser.
 */
final class EdgeCachingControllerStub
{
    use ResponseCachingTrait;

    /** @phpstan-ignore-next-line trait reads $currentUser */
    protected ?object $currentUser = null;

    public function __construct(
        private readonly ApplicationContext $context,
        protected Request $request,
    ) {
    }

    public function getContext(): ApplicationContext
    {
        return $this->context;
    }

    public function callEdgeCacheResponse(Response $response, string $pattern): Response
    {
        return $this->edgeCacheResponse($response, $pattern);
    }

    public function container(): FakeContainer
    {
        /** @var FakeContainer $c */
        $c = $this->context->getContainer();
        return $c;
    }
}

/**
 * PSR-11 container that records which ids were resolved.
 */
final class FakeContainer implements ContainerInterface
{
    /** @var array<int, string> */
    public array $resolved = [];

    /** @param array<string, mixed> $services */
    public function __construct(private readonly array $services)
    {
    }

    public function get(string $id): mixed
    {
        $this->resolved[] = $id;
        if (!isset($this->services[$id])) {
            throw new class ($id) extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
                public function __construct(string $id)
                {
                    parent::__construct("Service not found: {$id}");
                }
            };
        }
        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}

/**
 * EdgeCacheInterface spy that records seam calls and behaves like NullEdgeCache.
 */
final class SpyEdgeCache implements EdgeCacheInterface
{
    /** @var array<int, array{0:string,1:?string}> */
    public array $generateCacheHeadersCalls = [];

    /** @var array<int, string> */
    public array $otherCalls = [];

    public function isEnabled(): bool
    {
        $this->otherCalls[] = __FUNCTION__;
        return false;
    }

    public function getProvider(): ?string
    {
        $this->otherCalls[] = __FUNCTION__;
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function generateCacheHeaders(string $route, ?string $contentType = null): array
    {
        $this->generateCacheHeadersCalls[] = [$route, $contentType];
        return [];
    }

    public function purgeUrl(string $url): bool
    {
        $this->otherCalls[] = __FUNCTION__;
        return false;
    }

    public function purgeByTag(string $tag): bool
    {
        $this->otherCalls[] = __FUNCTION__;
        return false;
    }

    public function purgeAll(): bool
    {
        $this->otherCalls[] = __FUNCTION__;
        return false;
    }
}
